import contextlib
import copy
import importlib.util
import io
import json
from pathlib import Path
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch
import uuid

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "infrastructure/erp"))
spec = importlib.util.spec_from_file_location("sync_partnerpool", ROOT / "infrastructure/erp/sync_partnerpool.py")
sync = importlib.util.module_from_spec(spec)
spec.loader.exec_module(sync)


def source_row(**values):
    row = {field: None for field in sync.REQUIRED_COLUMNS}
    row.update(erp_partner_code="P000001", partner_name="Minta partner", partner_type="both",
               partner_active=1, address_active=1, addrid="000001", country="HU", default=1)
    row.update(values)
    return row


def normalized(rows=None, **kwargs):
    return sync.normalize_rows(rows if rows is not None else [source_row()],
                               batch_id=str(uuid.uuid4()), extracted_at="2026-11-10 01:00:00", **kwargs)


class FakeDatabase:
    def __init__(self, previous_count=1):
        self.database = "test_pool"
        self.rows = [{"batch_id": "old", "erp_partner_code": "OLD"} for _ in range(previous_count)]
        self.state = ["old", previous_count, "2026-11-09 01:00:00"]
        self.engines = [("InnoDB",), ("InnoDB",)]
        self.columns = [(name,) for name in sync.COLUMNS]
        self.fail_insert = False
        self.committed = False
        self.result = None
        self.executed = []

    def begin(self):
        self.backup = copy.deepcopy((self.rows, self.state))

    def commit(self):
        self.committed = True

    def rollback(self):
        self.rows, self.state = self.backup

    def cursor(self):
        return self

    def __enter__(self):
        return self

    def __exit__(self, *_):
        pass

    def execute(self, sql, params=()):
        self.executed.append(sql)
        if sql == "SELECT DATABASE()":
            self.result = (self.database,)
        elif sql.startswith("SELECT ENGINE"):
            self.result = self.engines
        elif sql.startswith("SELECT COLUMN_NAME"):
            self.result = self.columns
        elif sql.startswith("SELECT active_batch_id"):
            self.result = self.state
        elif sql.startswith("SELECT COUNT(*) FROM partnerpool"):
            self.result = (sum(row["batch_id"] == params[0] for row in self.rows),)
        elif sql.startswith("UPDATE partnerpool_sync_state"):
            self.state = [params[0], params[1], params[3]]
        elif sql.startswith("DELETE FROM partnerpool WHERE batch_id<>"):
            self.rows = [r for r in self.rows if r["batch_id"] == params[0]]
        else:
            raise AssertionError("Unexpected SQL outside the pool contract")

    def executemany(self, sql, values):
        self.executed.append(sql)
        assert sql.startswith("INSERT INTO partnerpool ")
        for value in values:
            self.rows.append(dict(zip(sync.COLUMNS, value)))
            if self.fail_insert:
                raise RuntimeError("Simulated connection interruption")

    def fetchone(self):
        return self.result

    def fetchall(self):
        return self.result


class NormalizeTests(unittest.TestCase):
    def test_user_query_aliases_json_lists_and_informational_address_type(self):
        row = normalized([source_row(Language="hu", Currency="huf", pricelist="Wholesale_HU",
            brands='["HT", " DV ", "ht"]', ordersheets='["T", "S", "B"]', **{"address type": "Számlázási,Szállítási"})])[0]
        self.assertEqual(row["language_code"], "HU")
        self.assertEqual(row["currency_code"], "HUF")
        self.assertEqual(row["price_list_code"], "Wholesale_HU")
        self.assertEqual(json.loads(row["brand_codes"]), ["HT", "DV"])
        self.assertEqual(json.loads(row["order_sheet_type_codes"]), ["T", "S", "B"])
        self.assertEqual(row["address_type"], "Számlázási,Szállítási")

    def test_previous_query_without_suggestions_remains_supported(self):
        row = normalized()[0]
        self.assertEqual(row["brand_codes"], "[]")
        self.assertEqual(row["order_sheet_type_codes"], "[]")
        self.assertIsNone(row["address_type"])

    def test_invalid_or_ambiguous_suggestions_are_rejected_without_logging_values(self):
        invalid = [source_row(brands='not-json'), source_row(brands='{"HT":true}'), source_row(brands='[1]'),
            source_row(ordersheets='[""]'), source_row(brands=json.dumps(["X" * 101])),
            source_row(brands=json.dumps([str(i) for i in range(201)])),
            source_row(Language="HU", language_code="EN"), source_row(**{"address type": "X" * 4001})]
        for row in invalid:
            with self.subTest(keys=list(row)), self.assertRaises(sync.PoolValidationError):
                normalized([row])

    def test_aliases_flags_and_leading_zero_codes(self):
        row = normalized()[0]
        self.assertEqual(row["addrid"], "000001")
        self.assertEqual(row["is_default"], 1)
        self.assertEqual(row["partner_type"], "both")
        self.assertEqual(len(row["row_key"]), 64)

    def test_optional_fields_country_mapping_and_whitespace(self):
        row = normalized([source_row(country="36", street=" A  utca\n 1. ")], country_map={"36": "hu"})[0]
        self.assertEqual(row["country"], "HU")
        self.assertEqual(row["street"], "A utca 1.")
        self.assertIsNone(row["currency_code"])
        self.assertEqual(normalized([source_row(country="36")])[0]["country"], "36")

    def test_missing_headers_invalid_type_booleans_and_lengths_fail(self):
        missing = source_row(); del missing["partner_name"]
        for row in [missing, source_row(partner_type="unknown"), source_row(partner_active=2),
                    source_row(partner_name="x" * 256), source_row(erp_partner_code=" ")]:
            with self.subTest(row_keys=list(row)):
                with self.assertRaises(sync.PoolValidationError):
                    normalized([row])

    def test_empty_and_duplicate_snapshots_are_rejected(self):
        for rows in [[], [source_row(), source_row(erp_partner_code="p000001")]]:
            with self.assertRaises(sync.PoolValidationError):
                normalized(rows)

    def test_header_inconsistency_fails_but_address_phone_may_differ(self):
        with self.assertRaises(sync.PoolValidationError):
            normalized([source_row(), source_row(addrid="2", partner_name="Más név")])
        rows = normalized([source_row(partner_phone="111"), source_row(addrid="2", partner_phone="222")])
        self.assertEqual(len(rows), 2)

    def test_partner_without_address_is_preserved_for_supplier_review(self):
        self.assertIsNone(normalized([source_row(addrid=None, address_name=None)])[0]["addrid"])


class PublishTests(unittest.TestCase):
    def test_missing_suggestion_columns_keep_the_previous_snapshot_intact(self):
        db = FakeDatabase(); db.columns = [item for item in db.columns if item[0] != "brand_codes"]
        before = copy.deepcopy((db.rows, db.state))
        with self.assertRaisesRegex(sync.PoolValidationError, "schema migrations"):
            sync.publish_snapshot(db, normalized(), database="test_pool")
        self.assertEqual((db.rows, db.state), before)
        self.assertFalse(db.committed)

    def test_suggestions_and_address_type_are_stored_with_the_snapshot(self):
        db = FakeDatabase()
        rows = normalized([source_row(brands='["HT"]', ordersheets='["B"]', Language="HU", Currency="HUF",
            pricelist="Wholesale_HU", **{"address type": "Szállítási"})])
        sync.publish_snapshot(db, rows, database="test_pool")
        self.assertEqual(db.rows, rows)
        self.assertEqual(json.loads(db.rows[0]["brand_codes"]), ["HT"])

    def test_success_atomically_replaces_snapshot(self):
        db = FakeDatabase()
        rows = normalized()
        result = sync.publish_snapshot(db, rows, database="test_pool")
        self.assertTrue(db.committed)
        self.assertEqual(db.rows, rows)
        self.assertEqual(db.state[0], rows[0]["batch_id"])
        self.assertEqual(result["partners"], 1)
        self.assertFalse(any("TRUNCATE" in sql or "CREATE " in sql or "RENAME " in sql for sql in db.executed))

    def test_insert_failure_rolls_back_partial_rows_and_pointer(self):
        db = FakeDatabase(); db.fail_insert = True
        before = copy.deepcopy((db.rows, db.state))
        with self.assertRaises(RuntimeError):
            sync.publish_snapshot(db, normalized(), database="test_pool")
        self.assertEqual((db.rows, db.state), before)
        self.assertFalse(db.committed)

    def test_large_drop_requires_explicit_override(self):
        db = FakeDatabase(previous_count=10)
        with self.assertRaises(sync.PoolValidationError):
            sync.publish_snapshot(db, normalized(), database="test_pool")
        self.assertEqual(len(db.rows), 10)
        self.assertTrue(sync.publish_snapshot(db, normalized(), database="test_pool", max_drop_fraction=1)["published"])

    def test_wrong_database_bad_engine_and_missing_schema_do_not_publish(self):
        for mode in ["database", "engine", "schema"]:
            db = FakeDatabase()
            if mode == "database": db.database = "production"
            if mode == "engine": db.engines = [("MyISAM",), ("InnoDB",)]
            if mode == "schema": db.state = None
            with self.assertRaises(sync.PoolValidationError):
                sync.publish_snapshot(db, normalized(), database="test_pool")
            self.assertFalse(db.committed)

    def test_older_extraction_and_reused_batch_are_rejected(self):
        db = FakeDatabase(); rows = normalized(); db.state[2] = "2026-11-11 01:00:00"
        with self.assertRaises(sync.PoolValidationError):
            sync.publish_snapshot(db, rows, database="test_pool")
        db.state[2] = None; db.rows[0]["batch_id"] = rows[0]["batch_id"]
        with self.assertRaises(sync.PoolValidationError):
            sync.publish_snapshot(db, rows, database="test_pool")

    def test_invalid_minimum_and_mixed_batch_rejected(self):
        with self.assertRaises(sync.PoolValidationError):
            sync.publish_snapshot(FakeDatabase(), normalized(), database="test_pool", min_rows=0)
        rows = normalized([source_row(), source_row(addrid="2")]); rows[1]["batch_id"] = str(uuid.uuid4())
        with self.assertRaises(sync.PoolValidationError):
            sync.publish_snapshot(FakeDatabase(), rows, database="test_pool")


class DriverAndCliTests(unittest.TestCase):
    def test_sql_login_uses_environment_password_with_odbc_escaping(self):
        config = {"connection_string": "DRIVER={ODBC Driver 18 for SQL Server};SERVER=sql;Encrypt=yes;",
                  "username": "pool_reader", "password_env": "POOL_TEST_SQL_PASSWORD"}
        with patch.dict(sync.os.environ, {"POOL_TEST_SQL_PASSWORD": " secret;part}end "}):
            connection_string = sync.source_connection_string(config)
        self.assertEqual(connection_string, config["connection_string"] + "UID={pool_reader};PWD={ secret;part}}end };")
        self.assertNotIn("PWD", config["connection_string"])
        self.assertEqual(sync.source_connection_string({"connection_string": "Trusted_Connection=yes;"}), "Trusted_Connection=yes;")

    def test_sql_login_rejects_missing_password_and_conflicting_authentication(self):
        config = {"connection_string": "SERVER=sql;", "username": "pool_reader", "password_env": "POOL_TEST_SQL_PASSWORD"}
        with patch.dict(sync.os.environ, {}, clear=True):
            with self.assertRaisesRegex(sync.PoolValidationError, "MSSQL password environment"):
                sync.source_connection_string(config)
        for attr in ["Trusted_Connection=yes", "Trusted_Connection=no", "UID=other", "PWD=hidden", "Authentication=SqlPassword", "Integrated Security=SSPI"]:
            with self.subTest(attr=attr), self.assertRaisesRegex(sync.PoolValidationError, "Remove authentication"):
                sync.source_connection_string({**config, "connection_string": "SERVER=sql;" + attr + ";"})
        with self.assertRaisesRegex(sync.PoolValidationError, "requires"):
            sync.source_connection_string({"connection_string": "SERVER=sql;", "password_env": "POOL_TEST_SQL_PASSWORD"})
        with patch.object(sync.os, "getenv", return_value="bad\x00password"), self.assertRaisesRegex(sync.PoolValidationError, "Invalid content"):
            sync.source_connection_string(config)

    def test_sql_batch_uses_one_connection_and_skips_non_row_results(self):
        row = source_row()
        class Cursor:
            __slots__ = ("index", "description", "sql")
            def __init__(self): self.index = 0; self.description = None
            def execute(self, sql): self.sql = sql
            def fetchall(self): return [tuple(row.values())]
            def nextset(self):
                self.index += 1
                if self.index == 1: self.description = [(c,) for c in row]; return True
                return False
        cursor = Cursor()
        connection = SimpleNamespace(cursor=lambda: cursor, close=lambda: None, timeout=0)
        with tempfile.TemporaryDirectory() as folder:
            Path(folder, "query.sql").write_text("SET NOCOUNT ON; SELECT ...", encoding="utf-8")
            connect = unittest.mock.Mock(return_value=connection)
            with patch.dict(sys.modules, {"pyodbc": SimpleNamespace(connect=connect)}), patch.dict(sync.os.environ, {"POOL_TEST_SQL_PASSWORD": "query-secret"}):
                result = sync.fetch_source({"connection_string": "SERVER=sql;", "username": "pool_reader", "password_env": "POOL_TEST_SQL_PASSWORD", "query_file": "query.sql", "timeout_seconds": 123}, Path(folder))
            connect.assert_called_once_with("SERVER=sql;UID={pool_reader};PWD={query-secret};", autocommit=True, timeout=20)
        self.assertEqual(result, [row])
        self.assertEqual(connection.timeout, 123)
        self.assertTrue(cursor.sql.startswith("SET NOCOUNT"))

    def test_dry_run_never_connects_to_mysql_and_errors_redact_credentials(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder, "config.json")
            path.write_text(json.dumps({"environment": "staging", "mssql": {}, "mysql": {}}), encoding="utf-8")
            with patch.object(sync, "fetch_source", return_value=[source_row()]), patch.object(sync, "connect_target") as connect:
                with contextlib.redirect_stdout(io.StringIO()): self.assertEqual(sync.main(["--config", str(path)]), 0)
                connect.assert_not_called()
            error = io.StringIO()
            with patch.object(sync, "fetch_source", side_effect=RuntimeError("PASSWORD=secret-test-value")), contextlib.redirect_stderr(error):
                self.assertEqual(sync.main(["--config", str(path)]), 1)
            self.assertNotIn("secret-test-value", error.getvalue())
            error = io.StringIO()
            with patch.dict(sync.os.environ, {"POOL_TEST_SQL_PASSWORD": "sql-secret-value"}), patch.dict(sys.modules, {"pyodbc": SimpleNamespace(connect=unittest.mock.Mock(side_effect=RuntimeError("PWD=sql-secret-value")))}):
                path.write_text(json.dumps({"environment": "staging", "mssql": {"connection_string": "SERVER=sql;", "username": "pool_reader", "password_env": "POOL_TEST_SQL_PASSWORD", "query_file": "query.sql"}}), encoding="utf-8")
                Path(folder, "query.sql").write_text("SELECT 1", encoding="utf-8")
                with contextlib.redirect_stderr(error):
                    self.assertEqual(sync.main(["--config", str(path)]), 1)
            self.assertNotIn("sql-secret-value", error.getvalue())

    def test_production_write_requires_explicit_authorization(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder, "config.json")
            path.write_text(json.dumps({"environment": "production", "mssql": {}, "mysql": {"database": "prod"}}), encoding="utf-8")
            with patch.object(sync, "fetch_source") as fetch, contextlib.redirect_stderr(io.StringIO()):
                self.assertEqual(sync.main(["--config", str(path), "--apply"]), 1)
                fetch.assert_not_called()

    def test_sql_both_branch_precedes_customer_and_contact_uses_linked_database(self):
        sql = (ROOT / "infrastructure/erp/partnerpool.sql").read_text(encoding="utf-8")
        self.assertLess(sql.index("THEN 'both'"), sql.index("THEN 'customer'"))
        self.assertIn("FROM [U4 LIVE].[HTO_ERP_LIVE].[dbo].[ols_employee] em", sql)
        self.assertIn("ORDER BY em.empid", sql)
        self.assertIn("INNER JOIN [U4 LIVE].[HTO_ERP_LIVE].[dbo].[ols_typeline] t", sql)


if __name__ == "__main__":
    unittest.main()
