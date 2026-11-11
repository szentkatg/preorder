"""Windows current-user DPAPI credential store. No plaintext files or machine scope."""
from __future__ import annotations

from contextlib import contextmanager
import ctypes
from ctypes import wintypes
import hashlib
import json
import os
from pathlib import Path
import re
import sys
import tempfile

MAGIC = b"PREORDER-PARTNERPOOL-DPAPI-V1\n"


class CredentialStoreError(ValueError):
    pass


def credential_fields(config: dict, *, source: bool, target: bool) -> list[tuple[str, str]]:
    fields = []
    if source:
        fields.append((config.get("mssql", {}).get("password_env"), "MSSQL jelszo"))
    if target:
        fields.append((config["mysql"].get("password_env"), "MySQL jelszo"))
        ssh = config.get("ssh", {})
        if ssh.get("enabled"):
            fields.extend([(ssh.get("password_env"), "Tarhely SSH jelszo"),
                           (ssh.get("key_password_env"), "SSH privat kulcs jelmondata")])
    result, seen = [], set()
    protected = {"path", "pythonpath", "pythonhome", "systemroot", "windir", "home", "userprofile", "localappdata", "temp", "tmp", "comspec", "pathext"}
    for name, label in fields:
        if name is None:
            continue
        if not isinstance(name, str) or not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]*", name) or name.casefold() in protected:
            raise CredentialStoreError("Invalid password environment-variable name in configuration")
        if name.casefold() in seen:
            raise CredentialStoreError("Each credential must use a separate environment-variable name")
        seen.add(name.casefold()); result.append((name, label))
    return result


def require_windows():
    if sys.platform != "win32":
        raise CredentialStoreError("Persistent credentials require Windows current-user DPAPI")


def dpapi(data: bytes, entropy: bytes, *, decrypt: bool = False) -> bytes:
    require_windows()
    if not data or len(data) > 1024 * 1024:
        raise CredentialStoreError("Invalid encrypted credential payload size")

    class Blob(ctypes.Structure):
        _fields_ = [("cbData", wintypes.DWORD), ("pbData", ctypes.POINTER(ctypes.c_ubyte))]

    crypt = ctypes.WinDLL("crypt32.dll", use_last_error=True)
    kernel = ctypes.WinDLL("kernel32.dll", use_last_error=True)
    function = crypt.CryptUnprotectData if decrypt else crypt.CryptProtectData
    function.argtypes = [ctypes.POINTER(Blob), ctypes.c_void_p if decrypt else wintypes.LPCWSTR,
                         ctypes.POINTER(Blob), ctypes.c_void_p, ctypes.c_void_p, wintypes.DWORD, ctypes.POINTER(Blob)]
    function.restype = wintypes.BOOL
    kernel.LocalFree.argtypes = [ctypes.c_void_p]; kernel.LocalFree.restype = ctypes.c_void_p
    input_buffer = ctypes.create_string_buffer(data)
    entropy_buffer = ctypes.create_string_buffer(entropy)
    input_blob = Blob(len(data), ctypes.cast(input_buffer, ctypes.POINTER(ctypes.c_ubyte)))
    entropy_blob = Blob(len(entropy), ctypes.cast(entropy_buffer, ctypes.POINTER(ctypes.c_ubyte)))
    output = Blob()
    try:
        # UI_FORBIDDEN=1; deliberately never use LOCAL_MACHINE=4.
        if not function(ctypes.byref(input_blob), None if decrypt else "Preorder partnerpool credentials",
                        ctypes.byref(entropy_blob), None, None, 1, ctypes.byref(output)):
            action = "decrypt" if decrypt else "encrypt"
            raise CredentialStoreError(f"Windows DPAPI could not {action} credentials; use the same Windows account and computer (error {ctypes.get_last_error()})")
        return ctypes.string_at(output.pbData, output.cbData)
    finally:
        ctypes.memset(input_buffer, 0, len(input_buffer))
        ctypes.memset(entropy_buffer, 0, len(entropy_buffer))
        if output.pbData:
            ctypes.memset(output.pbData, 0, output.cbData)
            kernel.LocalFree(ctypes.cast(output.pbData, ctypes.c_void_p))


def windows_account() -> str:
    require_windows()
    security = ctypes.WinDLL("secur32.dll", use_last_error=True)
    function = security.GetUserNameExW
    function.argtypes = [ctypes.c_int, wintypes.LPWSTR, ctypes.POINTER(wintypes.ULONG)]
    function.restype = wintypes.BOOL
    size = wintypes.ULONG(0)
    function(2, None, ctypes.byref(size))  # NameSamCompatible: DOMAIN\user.
    if size.value < 2 or size.value > 32768:
        raise CredentialStoreError("Cannot determine the current Windows account")
    buffer = ctypes.create_unicode_buffer(size.value)
    if not function(2, buffer, ctypes.byref(size)):
        raise CredentialStoreError("Cannot determine the current Windows account")
    return buffer.value


class CredentialStore:
    def __init__(self, config_path: Path, config: dict, *, directory: Path | None = None):
        allowed = credential_fields(config, source=True, target=True)
        self.allowed = {name for name, _ in allowed}
        mysql, mssql, ssh = (config.get(name, {}) for name in ("mysql", "mssql", "ssh"))
        identity = {"config_path": os.path.normcase(str(config_path.resolve())), "environment": config.get("environment"),
                    "mysql": {key: mysql.get(key) for key in ("host", "port", "database", "user", "password_env")},
                    "mssql": {key: mssql.get(key) for key in ("connection_string", "username", "password_env")},
                    "ssh": {key: ssh.get(key) for key in ("enabled", "host", "port", "username", "identity_file", "password_env", "key_password_env")}}
        self.scope = hashlib.sha256(json.dumps(identity, sort_keys=True, ensure_ascii=False).encode("utf-8")).hexdigest()
        if directory is None:
            local = os.environ.get("LOCALAPPDATA")
            if not local:
                raise CredentialStoreError("LOCALAPPDATA is unavailable for the Windows credential store")
            directory = Path(local) / "PreorderPartnerPool"
        self.directory = directory
        self.path = directory / ("credentials-" + self.scope + ".dpapi")

    def validate(self, values: dict):
        if not isinstance(values, dict) or set(values) != self.allowed:
            raise CredentialStoreError("Saved credentials do not match the configured credential names")
        if any(not isinstance(value, str) or not value or "\x00" in value for value in values.values()):
            raise CredentialStoreError("Saved credentials contain an empty or invalid value")

    def save(self, values: dict):
        require_windows(); self.validate(values)
        serialized = json.dumps(values, sort_keys=True, ensure_ascii=False, separators=(",", ":"))
        payload = json.dumps({"version": 1, "scope": self.scope, "secrets": values,
                              "checksum": hashlib.sha256(serialized.encode("utf-8")).hexdigest()}, ensure_ascii=False).encode("utf-8")
        encrypted = dpapi(payload, bytes.fromhex(self.scope))
        if dpapi(encrypted, bytes.fromhex(self.scope), decrypt=True) != payload:
            raise CredentialStoreError("DPAPI round-trip verification failed; credentials were not saved")
        self.directory.mkdir(mode=0o700, parents=True, exist_ok=True)
        if self.path.is_symlink():
            raise CredentialStoreError("Refusing to replace a credential-store symlink")
        temporary = None
        try:
            with tempfile.NamedTemporaryFile(dir=self.directory, prefix="credentials-", suffix=".tmp", delete=False) as stream:
                temporary = Path(stream.name)
                stream.write(MAGIC + encrypted); stream.flush(); os.fsync(stream.fileno())
            os.replace(temporary, self.path)
        finally:
            if temporary is not None and temporary.exists():
                temporary.unlink()

    def load(self) -> dict | None:
        require_windows()
        if self.path.is_symlink():
            raise CredentialStoreError("Invalid credential-store symlink")
        if not self.path.exists():
            return None
        if self.path.stat().st_size > 1024 * 1024:
            raise CredentialStoreError("Invalid credential-store file")
        encrypted = self.path.read_bytes()
        if not encrypted.startswith(MAGIC):
            raise CredentialStoreError("Invalid credential-store format")
        try:
            payload = json.loads(dpapi(encrypted[len(MAGIC):], bytes.fromhex(self.scope), decrypt=True))
            if payload.get("version") != 1 or payload.get("scope") != self.scope:
                raise CredentialStoreError("Credential-store scope or version mismatch")
            values = payload["secrets"]; self.validate(values)
            serialized = json.dumps(values, sort_keys=True, ensure_ascii=False, separators=(",", ":"))
            if payload.get("checksum") != hashlib.sha256(serialized.encode("utf-8")).hexdigest():
                raise CredentialStoreError("Credential-store checksum mismatch")
            return values
        except (UnicodeError, json.JSONDecodeError, KeyError, AttributeError, TypeError):
            raise CredentialStoreError("Credential store is damaged; save the passwords again") from None


@contextmanager
def saved_passwords(config_path: Path, config: dict, *, source: bool, target: bool):
    if sys.platform != "win32":
        yield
        return
    store = CredentialStore(config_path, config)
    values = store.load()
    previous = {}
    try:
        for name, _ in credential_fields(config, source=source, target=target):
            if values and not os.environ.get(name):
                previous[name] = os.environ.get(name)
                os.environ[name] = values[name]
        yield
    finally:
        for name, value in previous.items():
            if value is None:
                os.environ.pop(name, None)
            else:
                os.environ[name] = value
