"""Read-only MSSQL connection test; never prints raw driver errors or credentials."""
from __future__ import annotations

import argparse
import getpass
import json
import os
from pathlib import Path
import re
import sys
import warnings

import sync_partnerpool as sync


def safe_error(exc: Exception, phase: str) -> dict:
    state = str(exc.args[0]) if exc.args else ""
    detail = str(exc).casefold()
    result = {
        "status": "error", "phase": phase, "error_type": type(exc).__name__,
        "sqlstate": state if re.fullmatch(r"[A-Z0-9]{5}", state) else None,
        # These are diagnostic hints, not definitive diagnoses. Raw driver text
        # may include credentials, so emit only fixed labels/boolean flags.
        "certificate_error": any(word in detail for word in (
            "certificate", "tanúsítvány", "tanusitvany", "principal name")),
        "login_failed": "login failed" in detail or "(18456)" in detail,
        "timeout": "timeout" in detail or state in {"HYT00", "HYT01"},
    }
    if isinstance(exc, sync.PoolValidationError):
        result["message"] = str(exc)
    if isinstance(exc, json.JSONDecodeError):
        result.update(line=exc.lineno, column=exc.colno)
    return result


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", type=Path, default=Path(__file__).with_name("partnerpool-sync.config.json"))
    args = parser.parse_args(argv)
    phase, connection, cursor, prompted_env = "config", None, None, None
    try:
        config = json.loads(args.config.read_text(encoding="utf-8-sig"))["mssql"]
        password_env = config.get("password_env")
        if isinstance(password_env, str) and password_env and not os.environ.get(password_env):
            phase = "password"
            if not sys.stdin.isatty():
                raise sync.PoolValidationError("No interactive console; set the MSSQL password environment variable first")
            # Fail closed instead of allowing getpass to fall back to echoing.
            with warnings.catch_warnings():
                warnings.simplefilter("error", getpass.GetPassWarning)
                password = getpass.getpass("MSSQL jelszo (nem latszik): ")
            prompted_env = (password_env, os.environ.get(password_env))
            os.environ[password_env] = password
            del password
        phase = "config"
        connection_string = sync.source_connection_string(config)
        phase = "odbc_driver"
        import pyodbc

        phase = "connect"
        connection = pyodbc.connect(connection_string, autocommit=True, timeout=20)
        del connection_string
        connection.timeout = 20
        phase = "select_1"
        cursor = connection.cursor()
        cursor.execute("SELECT 1")
        if cursor.fetchone()[0] != 1:
            raise sync.PoolValidationError("Unexpected SELECT 1 result")
        print(json.dumps({"status": "ok", "phase": "select_1", "message": "SQL connection and SELECT 1 succeeded; ERP query and MySQL were not tested"}))
        return 0
    except Exception as exc:
        print(json.dumps(safe_error(exc, phase)))
        return 1
    finally:
        for resource in (cursor, connection):
            if resource is not None:
                try:
                    resource.close()
                except Exception:
                    pass  # Do not leak raw driver text during cleanup either.
        if prompted_env is not None:
            name, previous = prompted_env
            if previous is None:
                os.environ.pop(name, None)
            else:
                os.environ[name] = previous


if __name__ == "__main__":
    raise SystemExit(main())
