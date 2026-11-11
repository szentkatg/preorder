import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import socket
import struct
import sys
import tempfile
import threading
from types import SimpleNamespace
import unittest
from unittest.mock import MagicMock, Mock, patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "infrastructure/erp"))
spec = importlib.util.spec_from_file_location("sync_partnerpool", ROOT / "infrastructure/erp/sync_partnerpool.py")
sync = importlib.util.module_from_spec(spec)
spec.loader.exec_module(sync)


class FakeConnection:
    instances = []
    fail = False

    def __init__(self, **kwargs):
        self.options = kwargs
        self.closed = False
        self.socket = None
        self.instances.append(self)

    def connect(self, sock=None):
        self.socket = sock
        if self.fail:
            raise RuntimeError("private driver text")

    def close(self):
        self.closed = True


class SshTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.addCleanup(self.folder.cleanup)
        self.directory = Path(self.folder.name)
        (self.directory / "hosts").write_text("public host key", encoding="utf-8")
        self.config = {"environment": "staging", "mysql": {"host": "127.0.0.1", "port": 3306,
                       "database": "pool_test", "user": "reader", "password_env": "POOL_MYSQL"},
                       "ssh": {"enabled": True, "host": "example.test", "username": "ssh-user",
                               "password_env": "POOL_SSH", "known_hosts_file": "hosts"}}
        self.channel = Mock()
        self.transport = Mock()
        self.transport.open_channel.return_value = self.channel
        self.client = Mock()
        self.client.get_transport.return_value = self.transport
        self.client.get_host_keys.return_value.lookup.return_value = {"key": "pinned"}
        self.paramiko = SimpleNamespace(SSHClient=Mock(return_value=self.client), RejectPolicy=type("RejectPolicy", (), {}),
                                        BadHostKeyException=type("BadHostKeyException", (Exception,), {}),
                                        AuthenticationException=type("AuthenticationException", (Exception,), {}),
                                        ChannelException=type("ChannelException", (Exception,), {}))
        self.mysql = SimpleNamespace(connections=SimpleNamespace(Connection=FakeConnection), connect=Mock(), VERSION=(1, 2, 4, "final"))
        FakeConnection.instances, FakeConnection.fail = [], False
        self.env = patch.dict(os.environ, {"POOL_MYSQL": "private-mysql", "POOL_SSH": "private-ssh"})
        self.env.start(); self.addCleanup(self.env.stop)
        self.modules = patch.dict(sys.modules, {"paramiko": self.paramiko, "pymysql": self.mysql})
        self.modules.start(); self.addCleanup(self.modules.stop)

    def test_channel_only_no_listener_or_direct_mysql_connect_and_cleanup(self):
        with sync.target_connection(self.config, self.directory) as connection:
            self.assertIs(connection.socket, self.channel)
            self.assertTrue(connection.options["ssl_disabled"])
            self.assertTrue(connection.options["defer_connect"])
            with self.assertRaises(sync.PoolValidationError):
                connection.connect()  # No reconnect outside the channel.
        self.mysql.connect.assert_not_called()
        self.client.load_host_keys.assert_called_once_with(str(self.directory / "hosts"))
        self.assertIsInstance(self.client.set_missing_host_key_policy.call_args.args[0], self.paramiko.RejectPolicy)
        self.assertFalse(self.client.connect.call_args.kwargs["look_for_keys"])
        self.assertFalse(self.client.connect.call_args.kwargs["allow_agent"])
        self.transport.open_channel.assert_called_once_with("direct-tcpip", ("127.0.0.1", 3306), ("127.0.0.1", 0), timeout=20)
        self.assertTrue(connection.closed)
        self.channel.close.assert_called_once(); self.client.close.assert_called_once()

    def test_ssh_or_forwarding_failure_never_falls_back(self):
        for error in [self.paramiko.AuthenticationException("private-ssh"), self.paramiko.BadHostKeyException("bad key")]:
            self.client.connect.side_effect = error
            with self.subTest(error=type(error).__name__), self.assertRaises(sync.PoolValidationError):
                with sync.target_connection(self.config, self.directory):
                    self.fail("Connection unexpectedly opened")
        self.client.connect.side_effect = None
        self.transport.open_channel.side_effect = self.paramiko.ChannelException("not allowed")
        with self.assertRaisesRegex(sync.PoolValidationError, "forwarding was refused"):
            with sync.target_connection(self.config, self.directory):
                self.fail()
        self.assertEqual(FakeConnection.instances, [])
        self.mysql.connect.assert_not_called()

    def test_missing_or_unlisted_host_key_and_non_loopback_destinations_are_rejected(self):
        for modify in [lambda: self.config["mysql"].update(host="public.example.test"),
                       lambda: self.config["ssh"].update(known_hosts_file="missing"),
                       lambda: self.client.get_host_keys.return_value.lookup.__setattr__("return_value", None)]:
            self.config["mysql"]["host"] = "127.0.0.1"
            self.config["ssh"]["known_hosts_file"] = "hosts"
            modify()
            with self.assertRaises(sync.PoolValidationError):
                with sync.target_connection(self.config, self.directory):
                    self.fail()
        self.client.connect.assert_not_called()
        self.mysql.connect.assert_not_called()

    def test_driver_or_publish_error_closes_all_resources(self):
        FakeConnection.fail = True
        with self.assertRaises(RuntimeError):
            with sync.target_connection(self.config, self.directory):
                self.fail()
        self.assertTrue(FakeConnection.instances[0].closed)
        self.channel.close.assert_called_once(); self.client.close.assert_called_once()
        FakeConnection.fail = False
        with self.assertRaisesRegex(RuntimeError, "publish failed"):
            with sync.target_connection(self.config, self.directory) as connection:
                raise RuntimeError("publish failed")
        self.assertTrue(connection.closed)

    def test_private_key_authentication_disables_password_fallback(self):
        key = self.directory / "identity"; key.write_text("private test key placeholder", encoding="utf-8")
        self.config["ssh"].pop("password_env")
        self.config["ssh"].update(identity_file="identity", key_password_env="POOL_KEY_PASSWORD")
        with patch.dict(os.environ, {"POOL_KEY_PASSWORD": "private-passphrase"}):
            with sync.target_connection(self.config, self.directory):
                pass
        self.assertEqual(self.client.connect.call_args.kwargs["key_filename"], str(key))
        self.assertNotIn("password", self.client.connect.call_args.kwargs)
        self.assertEqual(self.client.connect.call_args.kwargs["passphrase"], "private-passphrase")

    def test_inactive_ssh_transport_is_rejected(self):
        self.transport.is_active.return_value = False
        with self.assertRaisesRegex(sync.PoolValidationError, "not authenticated"):
            with sync.target_connection(self.config, self.directory):
                self.fail()
        self.transport.open_channel.assert_not_called()

    def test_direct_mode_retains_tls_verification(self):
        self.config.pop("ssh")
        cursor = Mock(); cursor.fetchone.return_value = ("Ssl_cipher", "TLS-CIPHER")
        connection = MagicMock(); connection.cursor.return_value.__enter__.return_value = cursor
        self.mysql.connect.return_value = connection
        with sync.target_connection(self.config, self.directory):
            pass
        options = self.mysql.connect.call_args.kwargs
        self.assertTrue(options["ssl"].check_hostname)
        self.assertNotIn("ssl_disabled", options)
        self.paramiko.SSHClient.assert_not_called()
        connection.close.assert_called_once()

    def test_direct_mode_rejects_old_driver_before_any_network_connection(self):
        self.mysql.VERSION = (1, 1, 2, "final")
        with self.assertRaisesRegex(sync.PoolValidationError, "PyMySQL 1.2.4"):
            sync.connect_target(self.config["mysql"], self.directory)
        self.mysql.connect.assert_not_called()

    def test_read_only_target_cli_does_not_query_erp_or_publish(self):
        path = self.directory / "config.json"
        path.write_text(json.dumps(self.config), encoding="utf-8")
        cursor = Mock(); cursor.fetchone.return_value = ("pool_test", 1)
        connection = MagicMock(); connection.cursor.return_value.__enter__.return_value = cursor
        @contextlib.contextmanager
        def target(*args):
            yield connection
        output = io.StringIO()
        with patch.object(sync, "target_connection", target), patch.object(sync, "fetch_source") as source, patch.object(sync, "publish_snapshot") as publish, contextlib.redirect_stdout(output):
            self.assertEqual(sync.main(["--config", str(path), "--check-target"]), 0)
        self.assertEqual(json.loads(output.getvalue()), {"status": "ok", "target_connection": True, "transport": "ssh", "published": False})
        cursor.execute.assert_called_once_with("SELECT DATABASE(), 1")
        source.assert_not_called(); publish.assert_not_called()

    def test_prompt_restores_passwords_on_failure_without_echoing(self):
        with patch.dict(os.environ, {}, clear=True), patch.object(sys.stdin, "isatty", return_value=True), patch.object(sync.getpass, "getpass", side_effect=["mysql-secret", "ssh-secret"]):
            with self.assertRaises(RuntimeError):
                with sync.prompt_passwords(self.config, source=False, target=True):
                    self.assertEqual(os.environ["POOL_SSH"], "ssh-secret")
                    raise RuntimeError("after prompt")
            self.assertNotIn("POOL_SSH", os.environ)
            self.assertNotIn("POOL_MYSQL", os.environ)
        with patch.dict(os.environ, {}, clear=True), patch.object(sys.stdin, "isatty", return_value=False), self.assertRaises(sync.PoolValidationError):
            with sync.prompt_passwords(self.config, source=False, target=True):
                self.fail()

    def test_cli_ssh_failure_redacts_credentials(self):
        path = self.directory / "config.json"; path.write_text(json.dumps(self.config), encoding="utf-8")
        self.client.connect.side_effect = self.paramiko.AuthenticationException("private-ssh")
        error = io.StringIO()
        with contextlib.redirect_stderr(error):
            self.assertEqual(sync.main(["--config", str(path), "--check-target"]), 1)
        self.assertNotIn("private-ssh", error.getvalue())
        self.assertNotIn("private-mysql", error.getvalue())
        self.assertIn("SSH authentication failed", error.getvalue())

    def test_setup_keeps_working_sql_settings_backs_up_and_is_idempotent(self):
        self.config.pop("ssh")
        self.config["mysql"]["host"] = "szeganet.hu"
        self.config["mssql"] = {"connection_string": "working-sql;TrustServerCertificate=yes;", "username": "servio"}
        path = self.directory / "config.json"
        original = json.dumps(self.config)
        path.write_text(original, encoding="utf-8")
        (self.directory / "partnerpool-ssh_known_hosts").write_text("public pinned key", encoding="utf-8")
        result = sync.setup_ssh(self.config, path)
        updated = json.loads(path.read_text(encoding="utf-8"))
        self.assertEqual(updated["mssql"], self.config["mssql"])
        self.assertEqual(updated["mysql"]["database"], "pool_test")
        self.assertEqual(updated["mysql"]["host"], "127.0.0.1")
        self.assertTrue(updated["ssh"]["enabled"])
        self.assertEqual((self.directory / result["backup_file"]).read_text(encoding="utf-8"), original)
        self.assertFalse(sync.setup_ssh(updated, path)["changed"])

    def test_setup_missing_host_key_or_write_failure_keeps_original(self):
        self.config.pop("ssh")
        path = self.directory / "config.json"; original = json.dumps(self.config)
        path.write_text(original, encoding="utf-8")
        with self.assertRaises(sync.PoolValidationError):
            sync.setup_ssh(self.config, path)
        (self.directory / "partnerpool-ssh_known_hosts").write_text("public pinned key", encoding="utf-8")
        with patch.object(sync.os, "replace", side_effect=OSError("simulated write error")), self.assertRaises(OSError):
            sync.setup_ssh(self.config, path)
        self.assertEqual(path.read_text(encoding="utf-8"), original)
        self.assertEqual(list(self.directory.glob("partnerpool-config-*.tmp")), [])

    def test_apply_uses_managed_target_connection_and_returns_publication_result(self):
        self.config["mssql"] = {}
        path = self.directory / "config.json"; path.write_text(json.dumps(self.config), encoding="utf-8")
        connection = object()
        @contextlib.contextmanager
        def target(*args):
            yield connection
        rows = [{"erp_partner_code": "P1"}]
        published = {"published": True, "rows": 1, "partners": 1}
        with patch.object(sync, "target_connection", target), patch.object(sync, "fetch_source", return_value=rows), patch.object(sync, "normalize_rows", return_value=rows), patch.object(sync, "publish_snapshot", return_value=published) as publish, contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(sync.main(["--config", str(path), "--expect-environment", self.config["environment"], "--expect-database", "pool_test", "--apply"]), 0)
        self.assertIs(publish.call_args.args[0], connection)
        self.assertEqual(publish.call_args.kwargs["database"], "pool_test")


try:
    import paramiko
    import pymysql
except ImportError:
    paramiko = pymysql = None


@unittest.skipUnless(paramiko and pymysql, "Install requirements.txt for native SSH/MySQL protocol integration")
class NativeChannelTests(unittest.TestCase):
    """Real Paramiko + PyMySQL on loopback, with a minimal fake MySQL peer."""
    def test_wrong_host_key_is_rejected_before_password_authentication(self):
        server_key, wrong_key = paramiko.RSAKey.generate(2048), paramiko.RSAKey.generate(2048)
        listener = socket.socket(); listener.bind(("127.0.0.1", 0)); listener.listen(1); listener.settimeout(5)
        port = listener.getsockname()[1]
        authentication_calls, transports = [], []
        class Server(paramiko.ServerInterface):
            def check_auth_password(self, username, password):
                authentication_calls.append(username)
                return paramiko.AUTH_SUCCESSFUL
        def serve():
            try:
                sock, _ = listener.accept()
                transport = paramiko.Transport(sock); transports.append(transport)
                transport.set_log_channel("partnerpool.ssh.test_server")
                transport.add_server_key(server_key)
                transport.start_server(server=Server())
                transport.accept(2)
            except (EOFError, OSError, paramiko.SSHException):
                pass  # Expected when the client rejects our wrong key.
        thread = threading.Thread(target=serve, daemon=True); thread.start()
        try:
            with tempfile.TemporaryDirectory() as folder:
                directory = Path(folder)
                (directory / "hosts").write_text(f"[127.0.0.1]:{port} {wrong_key.get_name()} {wrong_key.get_base64()}\n", encoding="utf-8")
                config = {"mysql": {"host": "127.0.0.1", "database": "pool_test", "user": "reader", "password_env": "POOL_MYSQL"},
                          "ssh": {"enabled": True, "host": "127.0.0.1", "port": port, "username": "ssh-user", "known_hosts_file": "hosts", "password_env": "POOL_SSH"}}
                with patch.dict(os.environ, {"POOL_MYSQL": "mysql-secret", "POOL_SSH": "ssh-secret"}), self.assertRaisesRegex(sync.PoolValidationError, "host key mismatch"):
                    with sync.target_connection(config, directory):
                        self.fail("Wrong host key was accepted")
            self.assertEqual(authentication_calls, [])
        finally:
            for transport in transports:
                transport.close()
            listener.close(); thread.join(3)

    def test_verified_ssh_channel_carries_real_mysql_handshake_and_ping(self):
        key = paramiko.RSAKey.generate(2048)
        listener = socket.socket(); listener.bind(("127.0.0.1", 0)); listener.listen(1); listener.settimeout(10)
        port = listener.getsockname()[1]
        packets, errors, destinations, transports = [], [], [], []
        stop = threading.Event()

        class Server(paramiko.ServerInterface):
            def check_auth_password(self, username, password):
                return paramiko.AUTH_SUCCESSFUL if (username, password) == ("ssh-user", "ssh-secret") else paramiko.AUTH_FAILED

            def get_allowed_auths(self, username):
                return "password"

            def check_channel_direct_tcpip_request(self, chanid, origin, destination):
                destinations.append(destination)
                return paramiko.OPEN_SUCCEEDED

        def send(channel, payload, sequence):
            channel.sendall(len(payload).to_bytes(3, "little") + bytes([sequence]) + payload)

        def receive(channel):
            def exact(count):
                data = b""
                while len(data) < count:
                    piece = channel.recv(count - len(data))
                    if not piece:
                        return None
                    data += piece
                return data
            header = exact(4)
            return None if header is None else exact(int.from_bytes(header[:3], "little"))

        def serve():
            try:
                sock, _ = listener.accept()
                transport = paramiko.Transport(sock); transports.append(transport)
                transport.set_log_channel("partnerpool.ssh.test_server")
                transport.add_server_key(key); transport.start_server(server=Server())
                channel = transport.accept(10)
                if channel is None:
                    raise RuntimeError("SSH channel not opened")
                channel.settimeout(10)
                capabilities = 1 | 4 | 8 | 512 | 8192 | 32768 | 131072 | 524288
                salt = b"12345678abcdefghijkl"
                greeting = (b"\x0a8.0.0-test\x00" + struct.pack("<I", 1) + salt[:8] + b"\x00" +
                            struct.pack("<H", capabilities & 65535) + b"\x2d" + struct.pack("<H", 2) +
                            struct.pack("<H", capabilities >> 16) + b"\x15" + b"\x00" * 10 +
                            salt[8:] + b"\x00mysql_native_password\x00")
                send(channel, greeting, 0)
                auth = receive(channel)
                self.assertIn(b"mysql-user\x00", auth)
                self.assertNotIn(b"mysql-secret", auth)
                send(channel, b"\x00\x00\x00\x02\x00\x00\x00", 2)
                while not stop.is_set():
                    request = receive(channel)
                    if request is None or request[:1] == b"\x01":
                        break
                    packets.append(request)
                    send(channel, b"\x00\x00\x00\x02\x00\x00\x00", 1)
            except Exception as exc:
                if not stop.is_set():
                    errors.append(exc)

        thread = threading.Thread(target=serve, daemon=True); thread.start()
        try:
            with tempfile.TemporaryDirectory() as folder:
                directory = Path(folder)
                (directory / "hosts").write_text(f"[127.0.0.1]:{port} {key.get_name()} {key.get_base64()}\n", encoding="utf-8")
                config = {"mysql": {"host": "127.0.0.1", "database": "pool_test", "user": "mysql-user", "password_env": "POOL_MYSQL"},
                          "ssh": {"enabled": True, "host": "127.0.0.1", "port": port, "username": "ssh-user", "known_hosts_file": "hosts", "password_env": "POOL_SSH"}}
                with patch.dict(os.environ, {"POOL_MYSQL": "mysql-secret", "POOL_SSH": "ssh-secret"}):
                    with sync.target_connection(config, directory) as connection:
                        connection.ping(reconnect=False)
                        with self.assertRaises(sync.PoolValidationError):
                            connection.connect()
            thread.join(5)
            self.assertFalse(thread.is_alive())
            self.assertFalse(errors, str(errors))
            self.assertEqual(destinations, [("127.0.0.1", 3306)])
            self.assertTrue(any(packet[:1] == b"\x0e" for packet in packets))
        finally:
            stop.set()
            for transport in transports:
                transport.close()
            listener.close()
            thread.join(2)


if __name__ == "__main__":
    unittest.main()
