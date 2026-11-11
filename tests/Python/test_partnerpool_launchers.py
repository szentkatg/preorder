"""Execute CMD launchers with a local Python fixture, never real ERP/MySQL."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
ERP = ROOT / "infrastructure/erp"


@unittest.skipUnless(sys.platform == "win32", "Native Windows CMD tests")
class CmdLauncherTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.folder = tempfile.TemporaryDirectory(prefix="partnerpool CMD ! ")
        cls.addClassCleanup(cls.folder.cleanup)
        cls.directory = Path(cls.folder.name)
        subprocess.run([sys.executable, "-m", "venv", "--without-pip", str(cls.directory / ".venv-partnerpool")],
                       check=True, capture_output=True, timeout=40)
        for name in ["run-partnerpool-test.cmd", "run-partnerpool-production.cmd",
                     "save-passwords-and-check.cmd", "save-production-passwords-and-check.cmd"]:
            shutil.copyfile(ERP / name, cls.directory / name)
        # Fixture prints only harmless arguments and simulates any requested failure.
        (cls.directory / "sync_partnerpool.py").write_text(
            "import json, os, sys\n"
            "print(json.dumps({'arguments': sys.argv[1:], 'cwd': os.getcwd()}))\n"
            "if '--check-target' in sys.argv: print('fixture stderr', file=sys.stderr)\n"
            "sys.exit(int(os.environ.get('PARTNERPOOL_FIXTURE_EXIT', '0')))\n", encoding="utf-8")

    def setUp(self):
        for name in ["partnerpool-sync.config.json", "partnerpool-sync.production.config.json"]:
            (self.directory / name).write_text("{}", encoding="utf-8")
        for path in (self.directory / "logs").glob("*"):
            path.unlink()

    def run_cmd(self, name, *, result=0):
        return subprocess.run([os.environ.get("COMSPEC", "cmd.exe"), "/d", "/c", name],
                              cwd=self.directory,
                              env={**os.environ, "PARTNERPOOL_FIXTURE_EXIT": str(result)},
                              capture_output=True, text=True, timeout=20)

    def test_both_launchers_use_fixed_separate_targets_virtualenv_and_logs(self):
        for mode, environment, database, config_name in [
            ("test", "staging", "szeganet_preorder_test", "partnerpool-sync.config.json"),
            ("production", "production", "szeganet_elorendeles", "partnerpool-sync.production.config.json")]:
            with self.subTest(mode=mode):
                result = self.run_cmd(f"run-partnerpool-{mode}.cmd")
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                log = (self.directory / "logs" / f"partnerpool-{mode}.log").read_text()
                self.assertIn(f"{mode.upper()} START", log); self.assertIn(f"{mode.upper()} OK", log)
                invocation = json.loads(next(line for line in log.splitlines() if line.startswith("{")))
                self.assertEqual(invocation["arguments"], ["--config", str(self.directory / config_name),
                    "--expect-environment", environment, "--expect-database", database, "--apply",
                    *(["--allow-production"] if mode == "production" else [])])
                self.assertEqual(Path(invocation["cwd"]), self.directory)
                self.assertNotIn("--prompt-passwords", log)

    def test_failures_are_logged_and_original_exit_code_reaches_scheduler(self):
        for mode in ["test", "production"]:
            result = self.run_cmd(f"run-partnerpool-{mode}.cmd", result=17)
            self.assertEqual(result.returncode, 17)
            log = (self.directory / "logs" / f"partnerpool-{mode}.log").read_text()
            self.assertIn(f"{mode.upper()} ERROR 17", log)
            self.assertNotIn(f"{mode.upper()} OK", log)

    def test_missing_production_config_never_falls_back_to_existing_test_config(self):
        (self.directory / "partnerpool-sync.production.config.json").unlink()
        result = self.run_cmd("run-partnerpool-production.cmd")
        self.assertEqual(result.returncode, 2)
        log = (self.directory / "logs/partnerpool-production.log").read_text()
        self.assertIn("PRODUCTION ERROR 2", log)
        self.assertNotIn('"arguments"', log)

    def test_save_wrappers_only_save_and_check_no_apply_or_task_generation(self):
        for mode, name, config_name in [
            ("staging", "save-passwords-and-check.cmd", "partnerpool-sync.config.json"),
            ("production", "save-production-passwords-and-check.cmd", "partnerpool-sync.production.config.json")]:
            result = self.run_cmd(name)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            calls = [json.loads(line)["arguments"] for line in result.stdout.splitlines() if line.startswith("{")]
            self.assertEqual(len(calls), 3)
            self.assertTrue(all(call[1] == str(self.directory / config_name) for call in calls))
            self.assertTrue(all(call[call.index("--expect-environment") + 1] == mode for call in calls))
            self.assertIn("--save-passwords", calls[0]); self.assertIn("--check-target", calls[1])
            self.assertNotIn("--apply", result.stdout)
            self.assertNotIn("--prepare-task", result.stdout)
            self.assertEqual(list(self.directory.glob("*.xml")), [])
            self.assertIn("fixture stderr", result.stderr)

    def test_save_wrapper_stops_after_first_failure(self):
        result = self.run_cmd("save-production-passwords-and-check.cmd", result=17)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(len([line for line in result.stdout.splitlines() if line.startswith("{")]), 1)

    def test_cannot_start_sync_when_log_directory_cannot_be_created(self):
        logs = self.directory / "logs"
        if logs.exists():
            logs.rmdir()
        logs.write_text("fixture blocks directory creation", encoding="utf-8")
        try:
            result = self.run_cmd("run-partnerpool-production.cmd")
            self.assertNotEqual(result.returncode, 0)
            self.assertNotIn('"arguments"', result.stdout)
        finally:
            logs.unlink()

    def test_cannot_start_sync_when_log_file_cannot_be_opened(self):
        logs = self.directory / "logs"
        logs.mkdir(exist_ok=True)
        blocked_log = logs / "partnerpool-production.log"
        blocked_log.mkdir()
        try:
            result = self.run_cmd("run-partnerpool-production.cmd")
            self.assertNotEqual(result.returncode, 0)
            self.assertNotIn('"arguments"', result.stdout)
        finally:
            blocked_log.rmdir()


if __name__ == "__main__":
    unittest.main()
