import contextlib
import copy
import importlib.util
import io
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import MagicMock, patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "infrastructure/erp"))
import partnerpool_credentials as credentials

spec = importlib.util.spec_from_file_location("sync_partnerpool_credentials_test", ROOT / "infrastructure/erp/sync_partnerpool.py")
sync = importlib.util.module_from_spec(spec)
spec.loader.exec_module(sync)


def config():
    return {"environment": "staging", "mssql": {"connection_string": "SERVER=sql;", "username": "reader", "password_env": "POOL_SQL"},
            "mysql": {"host": "127.0.0.1", "database": "szeganet_preorder_test", "user": "pool_reader", "password_env": "POOL_MYSQL"},
            "ssh": {"enabled": True, "host": "ssh.example.test", "username": "ssh-user", "identity_file": "identity"}}


def fake_dpapi(data, entropy, *, decrypt=False):
    # Deliberately a fake codec for filesystem/unit tests, NOT encryption.
    return bytes(value ^ 173 for value in data)


class CredentialStoreTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory(); self.addCleanup(self.folder.cleanup)
        self.directory = Path(self.folder.name)
        self.path = self.directory / "config.json"
        self.config = config()
        self.store = credentials.CredentialStore(self.path, self.config, directory=self.directory / "vault")
        self.values = {"POOL_SQL": "sql-secret", "POOL_MYSQL": "mysql-secret"}
        self.crypto = patch.object(credentials, "dpapi", side_effect=fake_dpapi)
        self.crypto.start(); self.addCleanup(self.crypto.stop)
        self.platform = patch.object(credentials.sys, "platform", "win32")
        self.platform.start(); self.addCleanup(self.platform.stop)

    def test_save_load_has_no_plaintext_and_no_config_edit(self):
        original = json.dumps(self.config); self.path.write_text(original, encoding="utf-8")
        self.store.save(self.values)
        self.assertEqual(self.store.load(), self.values)
        self.assertTrue(self.store.path.read_bytes().startswith(credentials.MAGIC))
        self.assertNotIn(b"sql-secret", self.store.path.read_bytes())
        self.assertEqual(self.path.read_text(encoding="utf-8"), original)
        self.assertEqual(list(self.store.directory.glob("*.tmp")), [])

    def test_store_scope_separates_test_production_users_hosts_and_config_paths(self):
        identities = set()
        for change in [lambda c: None, lambda c: c.update(environment="production"),
                       lambda c: c["mysql"].update(database="production"), lambda c: c["mysql"].update(user="other"),
                       lambda c: c["ssh"].update(host="other.example.test"), lambda c: c["mssql"].update(username="other")]:
            altered = copy.deepcopy(self.config); change(altered)
            identities.add(credentials.CredentialStore(self.path, altered, directory=self.store.directory).scope)
        self.assertEqual(len(identities), 6)
        self.assertNotEqual(self.store.scope, credentials.CredentialStore(self.directory / "other.json", self.config, directory=self.store.directory).scope)
        changed_parameter = {**self.config, "min_rows": 500, "max_drop_fraction": 0.1}
        self.assertEqual(self.store.scope, credentials.CredentialStore(self.path, changed_parameter, directory=self.store.directory).scope)

    def test_missing_store_is_optional_and_nonwindows_storage_is_rejected(self):
        self.assertIsNone(self.store.load())
        with patch.object(credentials.sys, "platform", "linux"), self.assertRaises(credentials.CredentialStoreError):
            self.store.save(self.values)
        self.assertFalse(self.store.path.exists())

    def test_atomic_replace_failure_keeps_previous_working_store(self):
        self.store.save(self.values); original = self.store.path.read_bytes()
        with patch.object(credentials.os, "replace", side_effect=OSError("private filesystem error")), self.assertRaises(OSError):
            self.store.save({"POOL_SQL": "new-secret", "POOL_MYSQL": "newer-secret"})
        self.assertEqual(self.store.path.read_bytes(), original)
        self.assertEqual(list(self.store.directory.glob("*.tmp")), [])

    def test_invalid_format_checksum_scope_and_unexpected_names_are_rejected(self):
        self.store.save(self.values)
        original = self.store.path.read_bytes()
        payload = json.loads(fake_dpapi(original[len(credentials.MAGIC):], b"", decrypt=True))
        for name, value in [("version", 999), ("scope", "other"), ("checksum", "invalid"), ("secrets", {"PATH": "private"})]:
            changed = {**payload, name: value}
            self.store.path.write_bytes(credentials.MAGIC + fake_dpapi(json.dumps(changed).encode(), b""))
            with self.subTest(field=name), self.assertRaises(credentials.CredentialStoreError):
                self.store.load()
        self.store.path.write_bytes(b"plain-password-file")
        with self.assertRaises(credentials.CredentialStoreError):
            self.store.load()

    def test_empty_nul_missing_and_extra_passwords_are_rejected(self):
        for values in [{}, {**self.values, "POOL_SQL": ""}, {**self.values, "POOL_SQL": "bad\x00value"}, {**self.values, "EXTRA": "value"}]:
            with self.assertRaises(credentials.CredentialStoreError):
                self.store.save(values)

    def test_credential_names_cannot_override_system_settings_or_collide(self):
        for value in ["PATH", "LocalAppData", "bad name", "", 123, "POOL_SQL"]:
            altered = copy.deepcopy(self.config); altered["mysql"]["password_env"] = value
            with self.subTest(name=value), self.assertRaises(credentials.CredentialStoreError):
                credentials.credential_fields(altered, source=True, target=True)
        self.assertEqual(credentials.credential_fields(self.config, source=False, target=True), [("POOL_MYSQL", "MySQL jelszo")])

    def test_saved_passwords_preserve_explicit_environment_and_restore_after_failure(self):
        self.store.save(self.values)
        with patch.object(credentials, "CredentialStore", return_value=self.store), patch.dict(os.environ, {"POOL_SQL": "explicit-value"}, clear=True):
            with self.assertRaises(RuntimeError):
                with credentials.saved_passwords(self.path, self.config, source=True, target=True):
                    self.assertEqual(os.environ["POOL_SQL"], "explicit-value")
                    self.assertEqual(os.environ["POOL_MYSQL"], "mysql-secret")
                    raise RuntimeError("driver failure")
            self.assertNotIn("POOL_MYSQL", os.environ)
            self.assertEqual(os.environ["POOL_SQL"], "explicit-value")

    def test_default_store_is_under_windows_user_profile(self):
        with patch.dict(os.environ, {"LOCALAPPDATA": str(self.directory)}):
            store = credentials.CredentialStore(self.path, self.config)
        self.assertEqual(store.directory, self.directory / "PreorderPartnerPool")
        self.assertNotIn("sql-secret", str(store.path))

    def test_save_cli_prompts_once_and_does_not_connect_to_any_database(self):
        self.path.write_text(json.dumps(self.config), encoding="utf-8")
        output = io.StringIO()
        with patch.object(sync, "CredentialStore", return_value=self.store), patch.object(sys.stdin, "isatty", return_value=True), patch.object(sync.getpass, "getpass", side_effect=["sql-secret", "mysql-secret"]) as prompt, patch.object(sync, "fetch_source") as source, patch.object(sync, "target_connection") as target, contextlib.redirect_stdout(output):
            self.assertEqual(sync.main(["--config", str(self.path), "--save-passwords"]), 0)
        self.assertEqual(prompt.call_count, 2)
        self.assertTrue(json.loads(output.getvalue())["credentials_saved"])
        self.assertNotIn("sql-secret", output.getvalue()); self.assertNotIn("mysql-secret", output.getvalue())
        source.assert_not_called(); target.assert_not_called()
        self.assertEqual(self.store.load(), self.values)

    def test_normal_source_run_loads_saved_password_without_prompt(self):
        self.store.save(self.values); self.path.write_text(json.dumps(self.config), encoding="utf-8")
        fields = {name: None for name in sync.REQUIRED_COLUMNS}
        fields.update(erp_partner_code="P1", partner_name="Partner", partner_type="customer", partner_active=True, address_active=True, default=1)
        def fetch(*args):
            self.assertEqual(os.environ["POOL_SQL"], "sql-secret")
            self.assertNotIn("POOL_MYSQL", os.environ)
            return [fields]
        with patch.object(credentials, "CredentialStore", return_value=self.store), patch.dict(os.environ, {}, clear=True), patch.object(sync, "fetch_source", side_effect=fetch), patch.object(sync.getpass, "getpass") as prompt, contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(sync.main(["--config", str(self.path)]), 0)
            self.assertNotIn("POOL_SQL", os.environ)
        prompt.assert_not_called()

    def test_target_check_uses_saved_passwords_without_source_query_or_prompt(self):
        self.store.save(self.values); self.path.write_text(json.dumps(self.config), encoding="utf-8")
        connection = MagicMock()
        connection.cursor.return_value.__enter__.return_value.fetchone.return_value = ("szeganet_preorder_test", 1)
        @contextlib.contextmanager
        def target(*args):
            self.assertEqual(os.environ["POOL_MYSQL"], "mysql-secret")
            self.assertNotIn("POOL_SQL", os.environ)
            yield connection
        with patch.object(credentials, "CredentialStore", return_value=self.store), patch.dict(os.environ, {}, clear=True), patch.object(sync, "target_connection", target), patch.object(sync, "fetch_source") as source, patch.object(sync.getpass, "getpass") as prompt, contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(sync.main(["--config", str(self.path), "--check-target"]), 0)
            self.assertNotIn("POOL_MYSQL", os.environ)
        source.assert_not_called(); prompt.assert_not_called()

    def test_invalid_saved_store_fails_closed_before_database_operations(self):
        self.path.write_text(json.dumps(self.config), encoding="utf-8")
        error = io.StringIO()
        with patch.object(credentials, "CredentialStore", return_value=self.store), patch.object(self.store, "load", side_effect=credentials.CredentialStoreError("Credential store is damaged; save the passwords again")), patch.object(sync, "fetch_source") as source, contextlib.redirect_stderr(error):
            self.assertEqual(sync.main(["--config", str(self.path)]), 1)
        self.assertEqual(json.loads(error.getvalue())["phase"], "saved_credentials")
        self.assertNotIn("mysql-secret", error.getvalue())
        source.assert_not_called()

    def test_environment_and_database_mismatches_fail_before_credentials_or_network(self):
        self.path.write_text(json.dumps(self.config), encoding="utf-8")
        for arguments in [["--expect-environment", "production", "--allow-production"],
                          ["--expect-database", "szeganet_elorendeles"]]:
            error = io.StringIO()
            with patch.object(sync, "saved_passwords") as secrets, patch.object(sync, "fetch_source") as source, patch.object(sync, "target_connection") as target, contextlib.redirect_stderr(error):
                self.assertEqual(sync.main(["--config", str(self.path), "--apply", *arguments]), 1)
            self.assertEqual(json.loads(error.getvalue())["phase"], "config")
            secrets.assert_not_called(); source.assert_not_called(); target.assert_not_called()

    def test_old_test_task_command_is_retired_without_creating_files_or_connecting(self):
        self.path.write_text(json.dumps(self.config), encoding="utf-8")
        with patch.object(sync, "fetch_source") as source, patch.object(sync, "target_connection") as target, contextlib.redirect_stderr(io.StringIO()):
            self.assertEqual(sync.main(["--config", str(self.path), "--prepare-task"]), 1)
        source.assert_not_called(); target.assert_not_called()
        self.assertEqual(list(self.directory.glob("*.xml")), [])


@unittest.skipUnless(sys.platform == "win32", "Native Windows DPAPI tests")
class NativeDpapiTests(unittest.TestCase):
    def test_native_roundtrip_and_wrong_entropy(self):
        value = "dummy-native-secret-árvíz".encode("utf-8")
        encrypted = credentials.dpapi(value, b"test-scope")
        self.assertNotIn(value, encrypted)
        self.assertEqual(credentials.dpapi(encrypted, b"test-scope", decrypt=True), value)
        with self.assertRaises(credentials.CredentialStoreError):
            credentials.dpapi(encrypted, b"wrong-scope", decrypt=True)

    def test_native_store_and_windows_account(self):
        with tempfile.TemporaryDirectory() as folder:
            directory = Path(folder)
            store = credentials.CredentialStore(directory / "config.json", config(), directory=directory / "vault")
            values = {"POOL_SQL": "dummy-source-secret", "POOL_MYSQL": "dummy-target-secret"}
            store.save(values)
            self.assertEqual(store.load(), values)
            self.assertNotIn(b"dummy-source-secret", store.path.read_bytes())
        self.assertIn("\\", credentials.windows_account())

if __name__ == "__main__":
    unittest.main()
