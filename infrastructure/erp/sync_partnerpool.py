"""Publish a validated ERP partner/address snapshot. Default: read-only dry run.

Only partnerpool and partnerpool_sync_state are written. No master data,
permissions, accounts, prices, or orders are synchronized by this program.
"""
from __future__ import annotations

import argparse
from collections.abc import Iterable, Mapping
from contextlib import contextmanager, ExitStack
from datetime import datetime, timezone
import getpass
import hashlib
import json
import logging
import os
from pathlib import Path
import re
import ssl
import sys
import tempfile
import uuid
import warnings

from partnerpool_credentials import (
    CredentialStore, CredentialStoreError, credential_fields, require_windows,
    saved_passwords,
)


TEXT_FIELDS = {
    "erp_partner_code": 50, "partner_name": 255, "short_name": 255,
    "partner_type": 16, "partner_email": 512, "partner_phone": 255,
    "sales_rep_erp_partner_code": 50, "addrid": 100, "address_name": 255,
    "country": 64, "zip": 255, "city": 255, "street": 16000,
    "contact_name": 255, "address_email": 512, "address_phone": 255,
    "currency_code": 3, "language_code": 10, "price_list_code": 100, "address_type": 4000,
}
CODE_LIST_FIELDS = ("brand_codes", "order_sheet_type_codes")
FIELD_ALIASES = {"language": "language_code", "currency": "currency_code", "pricelist": "price_list_code",
                 "brands": "brand_codes", "ordersheets": "order_sheet_type_codes", "address type": "address_type"}
BOOL_FIELDS = ("partner_active", "address_active", "is_default")
OPTIONAL = {"currency_code", "language_code", "price_list_code", "address_type"}
REQUIRED_COLUMNS = (set(TEXT_FIELDS) - OPTIONAL) | {"partner_active", "address_active"}
COLUMNS = ("batch_id", "row_key", *TEXT_FIELDS, *CODE_LIST_FIELDS, *BOOL_FIELDS, "source_extracted_at")
HEADER_FIELDS = ("partner_name", "short_name", "partner_type", "partner_email",
                 "sales_rep_erp_partner_code", "partner_active")


class PoolValidationError(ValueError):
    pass


def text(value: object, field: str) -> str | None:
    if value is None:
        return None
    result = str(value).strip()
    if not result:
        return None
    if "\x00" in result or len(result) > TEXT_FIELDS[field]:
        raise PoolValidationError(f"Invalid length/content in {field}")
    return result


def boolean(value: object, field: str) -> int:
    if value is None or value == "":
        return 0
    normalized = str(value).strip().casefold()
    if normalized in {"1", "true", "yes", "y"}:
        return 1
    if normalized in {"0", "false", "no", "n"}:
        return 0
    raise PoolValidationError(f"Invalid boolean in {field}")


def code_list(value: object, field: str) -> str:
    if value is None or value == "":
        return "[]"
    if isinstance(value, str):
        try:
            value = json.loads(value)
        except (ValueError, TypeError):
            raise PoolValidationError(f"Invalid JSON code list in {field}") from None
    if not isinstance(value, list) or len(value) > 200:
        raise PoolValidationError(f"Invalid code list in {field}")
    codes, seen = [], set()
    for item in value:
        if not isinstance(item, str) or not item.strip() or len(item.strip()) > 100 or "\x00" in item:
            raise PoolValidationError(f"Invalid code in {field}")
        item = item.strip()
        if item.casefold() not in seen:
            codes.append(item)
            seen.add(item.casefold())
    return json.dumps(codes, ensure_ascii=False)


def normalize_rows(rows: Iterable[Mapping], *, batch_id: str, extracted_at: str,
                   country_map: Mapping | None = None) -> list[dict]:
    uuid.UUID(batch_id)
    result, seen, headers = [], set(), {}
    country_map = country_map or {}
    for number, incoming in enumerate(rows, 1):
        raw = {}
        for key, value in incoming.items():
            name = str(key).strip().casefold()
            name = FIELD_ALIASES.get(name, name)
            if name in raw:
                raise PoolValidationError("Ambiguous ERP result column aliases")
            raw[name] = value
        missing = REQUIRED_COLUMNS - raw.keys()
        if missing or not ({"default", "is_default"} & raw.keys()):
            raise PoolValidationError("Required ERP result columns missing")
        row = {field: text(raw.get(field), field) for field in TEXT_FIELDS}
        row.update({field: code_list(raw.get(field), field) for field in CODE_LIST_FIELDS})
        for field in ("currency_code", "language_code"):
            if row[field]:
                row[field] = row[field].upper()
        if not row["erp_partner_code"] or not row["partner_name"]:
            raise PoolValidationError("Partner code and name are required")
        row["partner_type"] = (row["partner_type"] or "").casefold()
        if row["partner_type"] not in {"customer", "supplier", "both"}:
            raise PoolValidationError("Invalid partner_type")
        row["partner_active"] = boolean(raw["partner_active"], "partner_active")
        row["address_active"] = boolean(raw["address_active"], "address_active")
        row["is_default"] = boolean(raw.get("is_default", raw.get("default")), "is_default")
        if row["country"] in country_map:
            row["country"] = str(country_map[row["country"]]).upper()
            if not re.fullmatch(r"[A-Z]{2}", row["country"]):
                raise PoolValidationError("Country mappings must be two-letter codes")
        if row["street"]:
            row["street"] = " ".join(row["street"].split())
        key = (row["erp_partner_code"].casefold(), (row["addrid"] or "").casefold())
        if key in seen:
            raise PoolValidationError("Duplicate ERP partner/address key")
        seen.add(key)
        header = tuple(row[field] for field in HEADER_FIELDS)
        if key[0] in headers and headers[key[0]] != header:
            raise PoolValidationError("Inconsistent repeated partner header data")
        headers[key[0]] = header
        # partner_phone comes from pa.tel in the supplied query, so it may
        # legitimately differ between addresses and is not a header invariant.
        row.update(batch_id=batch_id, source_extracted_at=extracted_at,
                   row_key=hashlib.sha256(json.dumps(key, ensure_ascii=False).encode("utf-8")).hexdigest())
        result.append(row)
    if not result:
        raise PoolValidationError("Empty snapshots are never published")
    return result


def source_connection_string(config: dict) -> str:
    base = config["connection_string"]
    if "username" not in config and "password_env" not in config:
        # Retain existing Windows-auth configurations when no SQL login is set.
        return base
    username, password_env = config.get("username"), config.get("password_env")
    if not isinstance(username, str) or not username.strip() or not isinstance(password_env, str) or not password_env.strip():
        raise PoolValidationError("SQL authentication requires mssql.username and mssql.password_env")
    if re.search(r"(?:^|;)\s*(?:UID|PWD|USER\s+ID|PASSWORD|Trusted_Connection|Integrated\s+Security|Authentication)\s*=", base, re.IGNORECASE):
        raise PoolValidationError("Remove authentication attributes from mssql.connection_string when using username/password_env")
    password = os.getenv(password_env)
    if not password:
        raise PoolValidationError("MSSQL password environment variable is not set")
    if "\x00" in username or "\x00" in password:
        raise PoolValidationError("Invalid content in MSSQL credentials")
    # Braced ODBC values preserve semicolons/whitespace. Escape closing braces
    # so a password cannot introduce additional connection-string attributes.
    def quoted(value: str) -> str:
        return "{" + value.replace("}", "}}") + "}"
    return base.rstrip(";") + ";UID=" + quoted(username) + ";PWD=" + quoted(password) + ";"


def fetch_source(config: dict, config_dir: Path) -> list[dict]:
    import pyodbc

    query_path = Path(config["query_file"])
    if not query_path.is_absolute():
        query_path = config_dir / query_path
    query = query_path.read_text(encoding="utf-8-sig")
    connection = pyodbc.connect(source_connection_string(config), autocommit=True, timeout=20)
    try:
        connection.timeout = int(config.get("timeout_seconds", 300))
        cursor = connection.cursor()
        cursor.execute(query)
        found = None
        while True:
            if cursor.description:
                columns = [str(item[0]).casefold() for item in cursor.description]
                if REQUIRED_COLUMNS.issubset(columns) and {"default", "is_default"}.intersection(columns):
                    if found is not None or len(columns) != len(set(columns)):
                        raise PoolValidationError("Ambiguous SQL result sets/columns")
                    found = [dict(zip(columns, row)) for row in cursor.fetchall()]
                else:
                    raise PoolValidationError("Unexpected SQL result set")
            if not cursor.nextset():
                break
        if found is None:
            raise PoolValidationError("No partner result set returned")
        return found
    finally:
        connection.close()


def connect_target(config: dict, config_dir: Path):
    import pymysql

    # Older PyMySQL releases could fall back before the post-connect TLS check.
    # Reject those drivers before opening a direct Internet connection.
    driver_version = getattr(pymysql, "VERSION", ())
    if len(driver_version) < 3 or not all(isinstance(part, int) for part in driver_version[:3]) or driver_version[:3] < (1, 2, 4):
        raise PoolValidationError("Direct TLS requires PyMySQL 1.2.4 or newer; update requirements-partnerpool.txt dependencies")
    password = os.environ.get(config["password_env"])
    if not password:
        raise PoolValidationError("MySQL password environment variable is not set")
    ca = config.get("ssl_ca")
    if ca and not Path(ca).is_absolute():
        ca = str(config_dir / ca)
    # Never silently fall back to plaintext or disable certificate validation.
    context = ssl.create_default_context(cafile=ca)
    connection = pymysql.connect(host=config["host"], port=int(config.get("port", 3306)),
                                 database=config["database"], user=config["user"], password=password,
                                 charset="utf8mb4", ssl=context, autocommit=False,
                                 connect_timeout=20, read_timeout=120, write_timeout=120)
    try:
        with connection.cursor() as cursor:
            cursor.execute("SHOW SESSION STATUS LIKE 'Ssl_cipher'")
            cipher = cursor.fetchone()
            if not cipher or not cipher[1]:
                raise PoolValidationError("MySQL TLS was not negotiated")
        return connection
    except Exception:
        connection.close()
        raise


@contextmanager
def target_connection(config: dict, config_dir: Path):
    """Use verified TLS or a host-key-verified SSH channel, with no fallback."""
    ssh = config.get("ssh", {})
    if not isinstance(ssh, dict) or not isinstance(ssh.get("enabled", False), bool):
        raise PoolValidationError("ssh.enabled must be a JSON boolean")
    if not ssh.get("enabled", False):
        connection = connect_target(config["mysql"], config_dir)
        try:
            yield connection
        finally:
            connection.close()
        return

    import paramiko
    import pymysql

    mysql = config["mysql"]
    # Without MySQL TLS, the SSH endpoint must connect only to its own loopback.
    if mysql.get("host") not in {"127.0.0.1", "::1"}:
        raise PoolValidationError("SSH mode requires mysql.host=127.0.0.1 or ::1 on the hosting server")
    known_hosts = Path(ssh["known_hosts_file"])
    if not known_hosts.is_absolute():
        known_hosts = config_dir / known_hosts
    if not known_hosts.is_file():
        raise PoolValidationError("SSH known-hosts file is missing; install the verified public host key first")
    key_path = ssh.get("identity_file")
    if bool(key_path) == bool(ssh.get("password_env")):
        raise PoolValidationError("Choose exactly one SSH identity_file or password_env")
    options = {}
    if key_path:
        key_path = Path(key_path)
        if not key_path.is_absolute():
            key_path = config_dir / key_path
        if not key_path.is_file():
            raise PoolValidationError("SSH private key file is missing")
        options["key_filename"] = str(key_path)
        if ssh.get("key_password_env"):
            options["passphrase"] = os.environ.get(ssh["key_password_env"])
            if not options["passphrase"]:
                raise PoolValidationError("SSH key passphrase environment variable is not set")
    else:
        options["password"] = os.environ.get(ssh["password_env"])
        if not options["password"]:
            raise PoolValidationError("SSH password environment variable is not set")
    mysql_password = os.environ.get(mysql["password_env"])
    if not mysql_password:
        raise PoolValidationError("MySQL password environment variable is not set")

    # Do not let Paramiko's exception logging expose raw server/driver text.
    logger = logging.getLogger("partnerpool.ssh")
    if not logger.handlers:
        logger.addHandler(logging.NullHandler())
    logger.propagate = False
    client, channel, connection = paramiko.SSHClient(), None, None
    try:
        client.set_log_channel("partnerpool.ssh")
        client.load_host_keys(str(known_hosts))
        client.set_missing_host_key_policy(paramiko.RejectPolicy())
        hostname, port = ssh["host"], int(ssh.get("port", 22))
        key_name = hostname if port == 22 else f"[{hostname}]:{port}"
        if not client.get_host_keys().lookup(key_name):
            raise PoolValidationError("SSH host is not in the verified known-hosts file; do not auto-accept a new key")
        try:
            client.connect(hostname=hostname, port=port, username=ssh["username"],
                           allow_agent=False, look_for_keys=False, timeout=20,
                           banner_timeout=20, auth_timeout=20, channel_timeout=20, **options)
        except paramiko.BadHostKeyException:
            raise PoolValidationError("SSH host key mismatch; verify the hosting server key before continuing") from None
        except paramiko.AuthenticationException:
            raise PoolValidationError("SSH authentication failed; verify the hosting SSH account or key") from None
        transport = client.get_transport()
        if transport is None or not transport.is_active() or not transport.is_authenticated():
            raise PoolValidationError("SSH transport is not authenticated and active")
        transport.set_keepalive(30)
        try:
            channel = transport.open_channel("direct-tcpip", (mysql["host"], int(mysql.get("port", 3306))),
                                             ("127.0.0.1", 0), timeout=20)
        except paramiko.ChannelException:
            raise PoolValidationError("SSH forwarding was refused; ask the hosting provider to permit direct-tcpip to local MySQL") from None
        if channel is None:
            raise PoolValidationError("SSH did not open a MySQL forwarding channel")
        channel.settimeout(20)

        class ChannelConnection(pymysql.connections.Connection):
            def connect(self, sock=None):
                if sock is not channel or not transport.is_active():
                    raise PoolValidationError("SSH-only MySQL connection cannot reconnect outside its channel")
                return super().connect(sock=sock)

        # No listening port, shell, background process, or external plaintext
        # socket: the authenticated SSH channel is the MySQL driver's socket.
        connection = ChannelConnection(host=mysql["host"], port=int(mysql.get("port", 3306)),
                                       database=mysql["database"], user=mysql["user"], password=mysql_password,
                                       charset="utf8mb4", ssl_disabled=True, defer_connect=True, autocommit=False,
                                       connect_timeout=20, read_timeout=120, write_timeout=120)
        connection.connect(sock=channel)
        yield connection
    finally:
        # Always close the MySQL session before its encrypted transport.
        for resource in (connection, channel, client):
            if resource is not None:
                try:
                    resource.close()
                except Exception:
                    pass


@contextmanager
def prompt_passwords(config: dict, *, source: bool, target: bool):
    """Optional interactive convenience; scheduled jobs never prompt by default."""
    fields = credential_fields(config, source=source, target=target)
    previous = {}
    try:
        for name, label in fields:
            if not name or os.environ.get(name):
                continue
            if not sys.stdin.isatty():
                raise PoolValidationError("Password prompting requires an interactive console")
            with warnings.catch_warnings():
                warnings.simplefilter("error", getpass.GetPassWarning)
                secret = getpass.getpass(label + " (nem latszik): ")
            previous.setdefault(name, os.environ.get(name))
            os.environ[name] = secret
            del secret
        yield
    finally:
        for name, value in previous.items():
            if value is None:
                os.environ.pop(name, None)
            else:
                os.environ[name] = value


def save_passwords(config: dict, config_path: Path) -> dict:
    require_windows()
    fields = credential_fields(config, source=True, target=True)
    if not fields:
        raise CredentialStoreError("No password environment variables are configured")
    if not sys.stdin.isatty():
        raise CredentialStoreError("Saving passwords requires an interactive console")
    values = {}
    for name, label in fields:
        with warnings.catch_warnings():
            warnings.simplefilter("error", getpass.GetPassWarning)
            values[name] = getpass.getpass(label + " (menteshez, nem latszik): ")
    store = CredentialStore(config_path, config)
    store.save(values)
    return {"status": "ok", "credentials_saved": True, "credential_count": len(values),
            "credential_store": str(store.path), "published": False}


def setup_ssh(config: dict, config_path: Path) -> dict:
    """One-time local config edit only; keep MSSQL settings and make a backup."""
    if config.get("ssh"):
        if config["ssh"].get("enabled") is True and config["mysql"].get("host") in {"127.0.0.1", "::1"}:
            return {"status": "ok", "ssh_configured": True, "changed": False, "published": False}
        raise PoolValidationError("SSH settings already exist; review them manually instead of overwriting them")
    known_hosts = config_path.parent / "partnerpool-ssh_known_hosts"
    if not known_hosts.is_file():
        raise PoolValidationError("Copy partnerpool-ssh_known_hosts next to the config before SSH setup")
    updated = {**config, "mysql": {**config["mysql"], "host": "127.0.0.1"},
               "ssh": {"enabled": True, "host": "szeganet.hu", "port": 22, "username": "szeganet",
                       "known_hosts_file": "partnerpool-ssh_known_hosts", "password_env": "PARTNERPOOL_SSH_PASSWORD"}}
    backup = config_path.with_name(config_path.name + ".before-ssh-" + uuid.uuid4().hex + ".bak")
    with backup.open("xb") as stream:
        stream.write(config_path.read_bytes())
    temporary_path = None
    try:
        with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8", dir=config_path.parent,
                                         prefix="partnerpool-config-", suffix=".tmp", delete=False) as stream:
            temporary_path = Path(stream.name)
            json.dump(updated, stream, ensure_ascii=False, indent=2)
            stream.write("\n")
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary_path, config_path)
    finally:
        if temporary_path is not None and temporary_path.exists():
            temporary_path.unlink()
    return {"status": "ok", "ssh_configured": True, "changed": True, "backup_file": backup.name, "published": False}


def publish_snapshot(connection, rows: list[dict], *, database: str, min_rows: int = 1,
                     max_drop_fraction: float = 0.2) -> dict:
    if min_rows < 1 or not 0 <= max_drop_fraction <= 1 or len(rows) < min_rows:
        raise PoolValidationError("Snapshot size/guard configuration is invalid")
    batch = rows[0]["batch_id"]
    if any(row["batch_id"] != batch for row in rows):
        raise PoolValidationError("Mixed snapshot batches")
    partner_count = len({row["erp_partner_code"].casefold() for row in rows})
    try:
        connection.begin()
        with connection.cursor() as cursor:
            cursor.execute("SELECT DATABASE()")
            if cursor.fetchone()[0] != database:
                raise PoolValidationError("Unexpected target database")
            cursor.execute("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('partnerpool','partnerpool_sync_state')")
            engines = cursor.fetchall()
            if len(engines) != 2 or any(item[0].casefold() != "innodb" for item in engines):
                raise PoolValidationError("Both pool tables must use InnoDB")
            cursor.execute("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partnerpool'")
            if set(COLUMNS) - {item[0] for item in cursor.fetchall()}:
                raise PoolValidationError("Run the partnerpool schema migrations before publishing; required pool columns are missing")
            cursor.execute("SELECT active_batch_id,row_count,source_extracted_at FROM partnerpool_sync_state WHERE id=1 FOR UPDATE")
            state = cursor.fetchone()
            if state is None:
                raise PoolValidationError("Run the partnerpool schema migration first")
            previous_time = state[2].strftime("%Y-%m-%d %H:%M:%S") if isinstance(state[2], datetime) else state[2]
            if previous_time and rows[0]["source_extracted_at"] < previous_time:
                raise PoolValidationError("An older extraction cannot replace a newer snapshot")
            if state[1] and len(rows) < int(state[1]) * (1 - max_drop_fraction):
                raise PoolValidationError("Snapshot unexpectedly shrank; previous pool retained")
            cursor.execute("SELECT COUNT(*) FROM partnerpool WHERE batch_id=%s", (batch,))
            if cursor.fetchone()[0]:
                raise PoolValidationError("Batch identifier already exists")
            sql = "INSERT INTO partnerpool (`" + "`,`".join(COLUMNS) + "`) VALUES (" + ",".join(["%s"] * len(COLUMNS)) + ")"
            for offset in range(0, len(rows), 500):
                cursor.executemany(sql, [tuple(row.get(column) for column in COLUMNS) for row in rows[offset:offset + 500]])
            cursor.execute("SELECT COUNT(*) FROM partnerpool WHERE batch_id=%s", (batch,))
            if int(cursor.fetchone()[0]) != len(rows):
                raise PoolValidationError("Inserted snapshot row count differs")
            cursor.execute("UPDATE partnerpool_sync_state SET active_batch_id=%s,row_count=%s,partner_count=%s,source_extracted_at=%s,synced_at=UTC_TIMESTAMP() WHERE id=1",
                           (batch, len(rows), partner_count, rows[0]["source_extracted_at"]))
            # DELETE is transactional on these InnoDB tables; TRUNCATE/RENAME/DDL
            # would implicitly commit and could expose an empty/partial pool.
            cursor.execute("DELETE FROM partnerpool WHERE batch_id<>%s", (batch,))
        connection.commit()
    except Exception:
        connection.rollback()
        raise
    return {"batch_id": batch, "rows": len(rows), "partners": partner_count, "published": True}


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", required=True, type=Path)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--apply", action="store_true", help="Actually replace the pool; default is source-only validation")
    mode.add_argument("--check-target", action="store_true", help="Check SSH/TLS and MySQL with SELECT only; do not read ERP or publish")
    mode.add_argument("--setup-ssh", action="store_true", help="Configure szeganet.hu SSH defaults locally with a config backup; no network or database operations")
    mode.add_argument("--save-passwords", action="store_true", help="Save configured passwords using Windows current-user DPAPI; no database operations")
    mode.add_argument("--prepare-task", action="store_true", help=argparse.SUPPRESS)
    parser.add_argument("--prompt-passwords", action="store_true", help="Ask for missing passwords on an interactive console; do not use for scheduled runs")
    parser.add_argument("--allow-production", action="store_true")
    parser.add_argument("--expect-environment", choices=("staging", "production", "development"), help="Reject a launcher/config environment mismatch before any connection")
    parser.add_argument("--expect-database", help="Reject a launcher/config target database mismatch before any connection")
    parser.add_argument("--allow-large-drop", action="store_true", help="Explicitly allow a validated nonempty snapshot to shrink by more than the configured guard")
    args = parser.parse_args(argv)
    phase = "config"
    try:
        config = json.loads(args.config.read_text(encoding="utf-8-sig"))
        environment = config.get("environment")
        if environment not in {"staging", "production", "development"}:
            raise PoolValidationError("Explicit environment is required")
        if args.expect_environment and environment != args.expect_environment:
            raise PoolValidationError("Configured environment does not match this launcher")
        if args.expect_database and config["mysql"]["database"] != args.expect_database:
            raise PoolValidationError("Configured target database does not match this launcher")
        if args.setup_ssh:
            print(json.dumps(setup_ssh(config, args.config)))
            return 0
        if args.save_passwords:
            phase = "save_credentials"
            print(json.dumps(save_passwords(config, args.config)))
            return 0
        if args.prepare_task:
            raise PoolValidationError("Test task generation is retired; run the test CMD manually and schedule only the production CMD")
        if args.apply and environment == "production" and not args.allow_production:
            raise PoolValidationError("Production writes require --allow-production")
        if args.apply and config["mysql"]["database"] == "szeganet_elorendeles" and environment != "production":
            raise PoolValidationError("Known production database cannot be labeled as staging")
        with ExitStack() as resources:
            phase = "saved_credentials"
            resources.enter_context(saved_passwords(args.config, config, source=not args.check_target,
                                                   target=args.apply or args.check_target))
            if args.prompt_passwords:
                phase = "password"
                resources.enter_context(prompt_passwords(config, source=not args.check_target, target=args.apply or args.check_target))
            if args.check_target:
                phase = "target_connection"
                with target_connection(config, args.config.parent) as connection:
                    phase = "target_check"
                    with connection.cursor() as cursor:
                        cursor.execute("SELECT DATABASE(), 1")
                        checked = cursor.fetchone()
                        if checked != (config["mysql"]["database"], 1):
                            raise PoolValidationError("Unexpected MySQL database or SELECT result")
                print(json.dumps({"status": "ok", "target_connection": True,
                                  "transport": "ssh" if config.get("ssh", {}).get("enabled") else "tls", "published": False}))
                return 0
            phase = "mssql_source"
            extracted = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
            rows = normalize_rows(fetch_source(config["mssql"], args.config.parent),
                                  batch_id=str(uuid.uuid4()), extracted_at=extracted,
                                  country_map=config.get("country_map"))
            if not args.apply:
                result = {"rows": len(rows), "partners": len({r["erp_partner_code"].casefold() for r in rows}), "published": False}
            else:
                phase = "target_connection"
                with target_connection(config, args.config.parent) as connection:
                    phase = "publish"
                    result = publish_snapshot(connection, rows, database=config["mysql"]["database"],
                                              min_rows=int(config.get("min_rows", 1)),
                                              max_drop_fraction=1 if args.allow_large_drop else float(config.get("max_drop_fraction", 0.2)))
        print(json.dumps({"status": "ok", **result}))
        return 0
    except Exception as exc:
        # Driver exception messages can contain connection strings or source PII.
        # Log only an error class and fixed validation messages, never raw SQL errors.
        message = str(exc) if isinstance(exc, (PoolValidationError, CredentialStoreError)) else "Driver/configuration operation failed; credentials withheld"
        diagnostic = {"status": "error", "phase": phase, "error_type": type(exc).__name__, "message": message}
        if exc.args and isinstance(exc.args[0], int):
            diagnostic["error_code"] = exc.args[0]
        print(json.dumps(diagnostic), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
