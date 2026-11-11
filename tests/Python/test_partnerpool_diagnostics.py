import contextlib
import importlib.util
import io
import json
from pathlib import Path
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "infrastructure/erp"))
sync_spec = importlib.util.spec_from_file_location("sync_partnerpool", ROOT / "infrastructure/erp/sync_partnerpool.py")
sync = importlib.util.module_from_spec(sync_spec)
sync_spec.loader.exec_module(sync)
spec = importlib.util.spec_from_file_location("diagnose_mssql", ROOT / "infrastructure/erp/diagnose_mssql.py")
diagnostic = importlib.util.module_from_spec(spec)
with patch.dict(sys.modules, {"sync_partnerpool": sync}):
    spec.loader.exec_module(diagnostic)


class DiagnosticsTests(unittest.TestCase):
    def run_diagnostic(self, config, connector, **options):
        output = io.StringIO()
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder, "config.json")
            path.write_text(json.dumps({"mssql": config}), encoding="utf-8")
            with patch.dict(sys.modules, {"pyodbc": SimpleNamespace(connect=connector)}), contextlib.redirect_stdout(output):
                status = diagnostic.main(["--config", str(path)])
        return status, json.loads(output.getvalue())

    def test_only_select_one_runs_and_resources_close(self):
        cursor = Mock(); cursor.fetchone.return_value = (1,)
        connection = Mock(); connection.cursor.return_value = cursor
        connector = Mock(return_value=connection)
        status, result = self.run_diagnostic({"connection_string": "Trusted_Connection=yes;"}, connector)
        self.assertEqual(status, 0)
        self.assertEqual(result["status"], "ok")
        connector.assert_called_once_with("Trusted_Connection=yes;", autocommit=True, timeout=20)
        cursor.execute.assert_called_once_with("SELECT 1")
        cursor.close.assert_called_once(); connection.close.assert_called_once()

    def test_certificate_login_and_timeout_flags_never_contain_raw_messages(self):
        for state, detail, flag in [
            ("08001", "certificate chain not trusted;PWD=secret-value", "certificate_error"),
            ("28000", "Login failed for user private-user (18456) PWD=secret-value", "login_failed"),
            ("HYT00", "Login timeout expired;PWD=secret-value", "timeout")]:
            with self.subTest(state=state):
                status, result = self.run_diagnostic({"connection_string": "private-config"}, Mock(side_effect=RuntimeError(state, detail)))
                self.assertEqual(status, 1)
                self.assertEqual(result["phase"], "connect")
                self.assertEqual(result["sqlstate"], state)
                self.assertTrue(result[flag])
                self.assertNotIn("secret-value", json.dumps(result))
                self.assertNotIn("private-user", json.dumps(result))

    def test_query_error_is_distinguished_from_connection_error(self):
        cursor = Mock(); cursor.execute.side_effect = RuntimeError("42000", "private query error")
        connection = Mock(); connection.cursor.return_value = cursor
        status, result = self.run_diagnostic({"connection_string": "private-config"}, Mock(return_value=connection))
        self.assertEqual(status, 1)
        self.assertEqual(result["phase"], "select_1")
        self.assertNotIn("private query error", json.dumps(result))
        connection.close.assert_called_once()

    def test_interactive_password_is_not_echoed_and_environment_is_restored(self):
        cursor = Mock(); cursor.fetchone.return_value = (1,)
        connection = Mock(); connection.cursor.return_value = cursor
        connector = Mock(return_value=connection)
        config = {"connection_string": "SERVER=sql;", "username": "reader", "password_env": "POOL_DIAG_PASSWORD"}
        with patch.dict(diagnostic.os.environ, {}, clear=True), patch.object(diagnostic.sys.stdin, "isatty", return_value=True), patch.object(diagnostic.getpass, "getpass", return_value="secret-value"):
            status, result = self.run_diagnostic(config, connector)
            self.assertNotIn("POOL_DIAG_PASSWORD", diagnostic.os.environ)
        self.assertEqual(status, 0)
        self.assertNotIn("secret-value", json.dumps(result))
        self.assertIn("PWD={secret-value}", connector.call_args.args[0])

    def test_unattended_missing_password_fails_before_connecting(self):
        connector = Mock()
        with patch.dict(diagnostic.os.environ, {}, clear=True), patch.object(diagnostic.sys.stdin, "isatty", return_value=False):
            status, result = self.run_diagnostic({"connection_string": "SERVER=sql;", "username": "reader", "password_env": "POOL_DIAG_PASSWORD"}, connector)
        self.assertEqual(status, 1)
        self.assertEqual(result["phase"], "password")
        connector.assert_not_called()


if __name__ == "__main__":
    unittest.main()
