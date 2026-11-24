#!/usr/bin/env python3
"""Private local Gmail draft writer; credentials stay with the existing report-gen client.

One bounded JSON request and response per Unix socket connection. There is no
send, update, delete, arbitrary URL, shell or credential-export operation.
"""
from __future__ import annotations

import argparse
import base64
from contextlib import contextmanager
from email import policy
from email.parser import BytesParser
import fcntl
import hashlib
import importlib
import json
import os
from pathlib import Path
import re
import signal
import socket
import socketserver
import sqlite3
import stat
import struct
import sys
import time
import uuid


MAX_REQUEST = 160 * 1024
MAX_RESPONSE = 16 * 1024
READ_TIMEOUT = 5
OPERATION_TIMEOUT = 90
HASH = re.compile(r"[a-f0-9]{64}\Z")
MAILBOX = re.compile(r"[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+\Z")
GMAIL_ID = re.compile(r"[A-Za-z0-9_-]{1,128}\Z")
COMMON = {"version", "operation", "account", "case_key"}
CREATE = COMMON | {"attempt_id", "fingerprint", "content_sha256", "recipient", "subject", "body"}
RECONCILE = COMMON | {"attempt_id", "content_sha256"}
STATES = {"pending", "uncertain", "verification_failed", "verified", "rejected"}
MESSAGES = {
    "invalid_request": "Invalid Gmail draft request.",
    "busy": "Another Gmail writer is active. No new draft was requested.",
    "account_mismatch": "The configured Gmail account could not be confirmed.",
    "authentication_failed": "The existing Gmail connection could not be used.",
    "case_locked": "This case already has a Gmail operation. Check its recorded result.",
    "known_receipt": "An existing Gmail receipt requires review. No new draft was requested.",
    "not_found": "No matching local Gmail operation was found.",
    "uncertain": "The Gmail result is unknown. Do not create another draft.",
    "verification_failed": "The existing draft could not be fully verified. No new draft was requested.",
    "storage_failed": "The local Gmail operation could not be safely recorded.",
    "unavailable": "The local Gmail writer is unavailable.",
}


class BridgeError(Exception):
    def __init__(self, code: str):
        super().__init__(MESSAGES.get(code, MESSAGES["unavailable"]))
        self.code = code


class OperationTimeout(Exception):
    pass


def content_hash(account: str, recipient: str, subject: str, body: str) -> str:
    """Wire contract: exact UTF-8 fields joined with three NUL separators."""
    return hashlib.sha256("\0".join((account, recipient, subject, body)).encode("utf-8")).hexdigest()


def _text(value: object, limit: int, multiline: bool = False) -> bool:
    if not isinstance(value, str) or not value:
        return False
    try:
        if len(value.encode("utf-8")) > limit:
            return False
    except UnicodeError:
        return False
    return not any(ord(c) < 32 and not (multiline and c in "\n\t") or ord(c) == 127 for c in value)


def validate_request(data: object, account: str) -> dict:
    if not isinstance(data, dict) or data.get("version") != 1 or isinstance(data.get("version"), bool):
        raise BridgeError("invalid_request")
    operation = data.get("operation")
    fields = CREATE if operation == "create" else RECONCILE if operation == "reconcile" else COMMON if operation == "status" else None
    if fields is None or set(data) != fields or data.get("account") != account:
        raise BridgeError("invalid_request")
    if not isinstance(data.get("case_key"), str) or not HASH.fullmatch(data["case_key"]):
        raise BridgeError("invalid_request")
    if operation != "status":
        try:
            if not isinstance(data["attempt_id"], str) or str(uuid.UUID(data["attempt_id"])) != data["attempt_id"]:
                raise ValueError()
        except (ValueError, AttributeError):
            raise BridgeError("invalid_request") from None
        if not isinstance(data["content_sha256"], str) or not HASH.fullmatch(data["content_sha256"]):
            raise BridgeError("invalid_request")
    if operation == "create":
        if (not isinstance(data["fingerprint"], str) or not HASH.fullmatch(data["fingerprint"])
                or not _text(data["recipient"], 254) or not MAILBOX.fullmatch(data["recipient"])
                or not _text(data["subject"], 1024) or not data["subject"].strip()
                or not _text(data["body"], 128 * 1024, multiline=True) or not data["body"].strip()
                or content_hash(account, data["recipient"], data["subject"], data["body"]) != data["content_sha256"]):
            raise BridgeError("invalid_request")
    return data


def private_directory(path: Path) -> None:
    if path.is_symlink():
        raise BridgeError("storage_failed")
    path.mkdir(mode=0o700, parents=True, exist_ok=True)
    info = path.stat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.getuid():
        raise BridgeError("storage_failed")
    path.chmod(0o700)


@contextmanager
def file_lock(path: Path):
    fd = os.open(path, os.O_CREAT | os.O_RDWR | getattr(os, "O_NOFOLLOW", 0), 0o600)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid():
            raise BridgeError("storage_failed")
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise BridgeError("busy") from None
        yield
    finally:
        os.close(fd)


class Ledger:
    """One durable operation per account and case, including uncertain attempts."""
    def __init__(self, directory: Path):
        private_directory(directory)
        self.path = directory / "gmail-drafts.sqlite3"
        if self.path.is_symlink():
            raise BridgeError("storage_failed")
        fd = os.open(self.path, os.O_CREAT | os.O_RDWR | getattr(os, "O_NOFOLLOW", 0), 0o600)
        try:
            if os.fstat(fd).st_uid != os.getuid():
                raise BridgeError("storage_failed")
            os.fchmod(fd, 0o600)
        finally:
            os.close(fd)
        with self.connect() as db:
            db.execute("""CREATE TABLE IF NOT EXISTS gmail_attempt (
                account TEXT NOT NULL, case_key TEXT NOT NULL, attempt_id TEXT NOT NULL,
                fingerprint TEXT NOT NULL, content_sha256 TEXT NOT NULL,
                recipient TEXT NOT NULL, subject TEXT NOT NULL, body TEXT NOT NULL,
                state TEXT NOT NULL, draft_id TEXT, message_id TEXT, error_code TEXT,
                safe_to_retry INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
                PRIMARY KEY(account, attempt_id)
            )""")
            db.execute("CREATE UNIQUE INDEX IF NOT EXISTS gmail_active_case ON gmail_attempt(account,case_key) WHERE state <> 'rejected'")

    @contextmanager
    def connect(self):
        db = sqlite3.connect(self.path, timeout=2)
        try:
            db.row_factory = sqlite3.Row
            db.execute("PRAGMA synchronous=FULL")
            with db:
                yield db
        finally:
            db.close()

    def get(self, account: str, case_key: str, attempt_id: str | None = None, active: bool = False) -> dict | None:
        with self.connect() as db:
            sql, params = "SELECT * FROM gmail_attempt WHERE account=? AND case_key=?", [account, case_key]
            if attempt_id is not None:
                sql += " AND attempt_id=?"
                params.append(attempt_id)
            if active:
                sql += " AND (state<>'rejected' OR safe_to_retry=0)"
            sql += " ORDER BY (state<>'rejected') DESC, rowid DESC LIMIT 1"
            row = db.execute(sql, params).fetchone()
        return dict(row) if row else None

    def reserve(self, request: dict, state: str = "pending", code: str | None = None, safe: bool = False) -> dict:
        now = int(time.time())
        fields = ("account", "case_key", "attempt_id", "fingerprint", "content_sha256", "recipient", "subject", "body")
        with self.connect() as db:
            db.execute("INSERT INTO gmail_attempt (" + ",".join(fields) + ",state,error_code,safe_to_retry,created_at,updated_at) VALUES (" + ",".join("?" for _ in range(13)) + ")",
                       tuple(request[k] for k in fields) + (state, code, int(safe), now, now))
        return self.get(request["account"], request["case_key"], request["attempt_id"])

    def reject(self, request: dict, code: str, safe: bool) -> dict:
        try:
            return self.reserve(request, "rejected", code, safe)
        except sqlite3.IntegrityError:
            row = self.get(request["account"], request["case_key"], request["attempt_id"])
            if row and row["content_sha256"] == request["content_sha256"] and row["fingerprint"] == request["fingerprint"]:
                return row
            raise BridgeError("case_locked") from None

    def update(self, row: dict, state: str, *, draft_id: str | None = None,
               message_id: str | None = None, error_code: str | None = None) -> dict:
        if state not in STATES:
            raise BridgeError("storage_failed")
        with self.connect() as db:
            changed = db.execute("""UPDATE gmail_attempt SET state=?, draft_id=COALESCE(?,draft_id),
                message_id=COALESCE(?,message_id), error_code=?, updated_at=?
                WHERE account=? AND case_key=? AND attempt_id=? AND content_sha256=?""",
                (state, draft_id, message_id, error_code, int(time.time()), row["account"], row["case_key"], row["attempt_id"], row["content_sha256"])).rowcount
            if changed != 1:
                raise BridgeError("storage_failed")
        return self.get(row["account"], row["case_key"], row["attempt_id"])


class ReportGenClient:
    """Only the existing report-gen authentication code touches OAuth files."""
    def __init__(self, root: Path):
        self.root = root.resolve()
        self.gmail = None
        self.reader = None
        self.writer = None

    def authenticate(self, account: str) -> None:
        source = self.root / "src"
        if str(source) not in sys.path:
            sys.path.insert(0, str(source))
        gmail = importlib.import_module("report_gen.gmail")
        sync = importlib.import_module("report_gen.gmail_sync")
        if not Path(gmail.__file__).resolve().is_relative_to(source / "report_gen"):
            raise BridgeError("authentication_failed")
        token_path = self.root / ".local/secrets/gmail-token.json"
        # No duplicate OAuth loader, token store, login flow, or token output.
        data = gmail.token_data(token_path)
        token = gmail.access_token(token_path, data)
        if sync.SentReadClient(token).profile().lower() != account:
            raise BridgeError("account_mismatch")
        self.gmail = gmail
        self.reader = gmail.DraftReadClient(token)
        self.writer = gmail.DraftOnlyClient(token)

    def encode(self, recipient: str, subject: str, body: str) -> bytes:
        return self.gmail.encode_message(recipient, subject, body)

    def create(self, raw: bytes) -> dict:
        return self.writer.create_draft(base64.urlsafe_b64encode(raw).decode("ascii"))

    def read(self, draft_id: str) -> dict:
        return self.reader._get("drafts/" + draft_id, {"format": "raw"})


def verify_draft(stored: object, row: dict) -> str:
    """Verify the stored recipient/body bytes; a successful POST is insufficient."""
    if not isinstance(stored, dict) or stored.get("id") != row["draft_id"]:
        raise BridgeError("verification_failed")
    message = stored.get("message")
    if not isinstance(message, dict):
        raise BridgeError("verification_failed")
    labels, encoded, message_id = message.get("labelIds"), message.get("raw"), message.get("id")
    if (not isinstance(labels, list) or "DRAFT" not in labels or "SENT" in labels
            or not isinstance(message_id, str) or not GMAIL_ID.fullmatch(message_id)
            or not isinstance(encoded, str) or len(encoded) > 1_000_000):
        raise BridgeError("verification_failed")
    try:
        raw = base64.b64decode(encoded + "=" * (-len(encoded) % 4), altchars=b"-_", validate=True)
        mail = BytesParser(policy=policy.default).parsebytes(raw)
        if (mail.defects or mail.is_multipart() or mail.get_content_type() != "text/plain"
                or mail.get_content_charset() != "utf-8"
                or len(mail.get_all("To", [])) != 1 or len(mail.get_all("Subject", [])) != 1
                or mail.get_all("Cc", []) or mail.get_all("Bcc", [])
                or str(mail["To"]) != row["recipient"] or str(mail["Subject"]) != row["subject"]
                or mail.get_payload(decode=True).decode("utf-8") != row["body"]):
            raise BridgeError("verification_failed")
    except (ValueError, TypeError, UnicodeError, AttributeError):
        raise BridgeError("verification_failed") from None
    return message_id


def public_receipt(row: dict, reused: bool = False) -> dict:
    result = {"ok": row["state"] == "verified", "state": row["state"],
              "attempt_id": row["attempt_id"], "account": row["account"],
              "content_sha256": row["content_sha256"], "reused": reused,
              "safe_to_retry": row["state"] == "rejected" and bool(row.get("safe_to_retry", False))}
    for key in ("draft_id", "message_id"):
        if row.get(key):
            result[key] = row[key]
    if row["state"] != "verified":
        code = row.get("error_code") or ("uncertain" if row["state"] in ("pending", "uncertain") else "verification_failed")
        result.update(error_code=code, error=MESSAGES.get(code, MESSAGES["unavailable"]))
    return result


def rejected(code: str, request: dict | None = None) -> dict:
    response = {"ok": False, "state": "busy" if code == "busy" else "rejected",
                "error_code": code, "error": MESSAGES.get(code, MESSAGES["unavailable"]), "safe_to_retry": False}
    if request:
        for key in ("attempt_id", "account", "content_sha256"):
            if key in request:
                response[key] = request[key]
    return response


class Coordinator:
    def __init__(self, root: Path, account: str, receipt_dir: Path, client_factory=None,
                 helper_receipt_dir: Path | None = None):
        if not MAILBOX.fullmatch(account) or account != account.lower():
            raise BridgeError("invalid_request")
        self.root, self.account = root, account
        self.ledger = Ledger(receipt_dir)
        self.helper_dir = helper_receipt_dir or Path.home() / ".local/share/report-gen/gmail-drafts"
        self.client_factory = client_factory or (lambda: ReportGenClient(root))

    def handle(self, data: object) -> dict:
        request = None
        try:
            request = validate_request(data, self.account)
            if request["operation"] == "status":
                row = self.ledger.get(self.account, request["case_key"])
                return public_receipt(row, reused=True) if row else rejected("not_found", request)
            # Same physical locks used by the existing host pipeline and helper.
            private_directory(self.helper_dir)
            with file_lock(self.root / ".local/pipeline-gmail.lock"):
                with file_lock(self.helper_dir / ".gmail.lock"):
                    return self._write_or_reconcile(request)
        except BridgeError as error:
            return self._failure(request, error.code)
        except (OSError, sqlite3.Error):
            return self._failure(request, "storage_failed")
        except Exception:
            # Never return a provider exception, request, body, path or token.
            return self._failure(request, "unavailable")

    def _failure(self, request: dict | None, code: str) -> dict:
        if request is None or request["operation"] == "status":
            return rejected(code, request)
        try:
            row = self.ledger.get(self.account, request["case_key"], request["attempt_id"])
            if row:
                # A failed read/lock cannot turn an existing create into a safe retry.
                result = public_receipt(row, reused=True)
                if request["operation"] == "reconcile":
                    result.update(ok=False, safe_to_retry=False, error_code=code, error=MESSAGES.get(code, MESSAGES["unavailable"]))
                return result
            if request["operation"] == "create":
                safe = code in {"busy", "authentication_failed", "account_mismatch"}
                return public_receipt(self.ledger.reject(request, code, safe))
        except Exception:
            pass
        return rejected(code, request)

    def _write_or_reconcile(self, request: dict) -> dict:
        row = self.ledger.get(self.account, request["case_key"], request["attempt_id"])
        if row:
            if row["attempt_id"] != request["attempt_id"] or row["content_sha256"] != request["content_sha256"]:
                return rejected("case_locked", request)
            if request["operation"] == "create":
                if row["fingerprint"] != request["fingerprint"]:
                    return rejected("case_locked", request)
                return public_receipt(row, reused=True)
            if row["state"] == "rejected":
                return public_receipt(row, reused=True)
            if not row["draft_id"]:
                return public_receipt(self.ledger.update(row, "uncertain", error_code="uncertain"), reused=True)
        elif request["operation"] == "reconcile":
            return rejected("not_found", request)
        elif self.ledger.get(self.account, request["case_key"], active=True):
            return public_receipt(self.ledger.reject(request, "case_locked", safe=False))

        client = self.client_factory()
        try:
            client.authenticate(self.account)
        except BridgeError:
            raise
        except Exception:
            raise BridgeError("authentication_failed") from None

        if row is None:
            raw = client.encode(request["recipient"], request["subject"], request["body"])
            ident = hashlib.sha256(self.account.encode("utf-8") + b"\0" + raw).hexdigest()
            # A positive existing helper receipt blocks. Absence proves nothing
            # about other tools, other revisions, or sent mail.
            if (self.helper_dir / (ident + ".json")).exists():
                return public_receipt(self.ledger.reject(request, "known_receipt", safe=False))
            try:
                row = self.ledger.reserve(request)
            except sqlite3.IntegrityError:
                return rejected("case_locked", request)
            try:
                result = client.create(raw)
                ident = result.get("draft_id") if isinstance(result, dict) else None
                if not isinstance(ident, str) or not GMAIL_ID.fullmatch(ident):
                    raise ValueError()
            except Exception:
                return public_receipt(self.ledger.update(row, "uncertain", error_code="uncertain"))
            # Persist the known ID before any readback. A killed process or lost
            # socket response must never turn a real draft into a create retry.
            try:
                row = self.ledger.update(row, "verification_failed", draft_id=ident, error_code="verification_failed")
            except Exception:
                # Expose a known ID even if disk failure prevents persisting it.
                # The pre-POST pending record still blocks every later create.
                row.update(state="verification_failed", draft_id=ident, error_code="storage_failed")
                return public_receipt(row)

        try:
            message_id = verify_draft(client.read(row["draft_id"]), row)
        except Exception:
            return public_receipt(self.ledger.update(row, "verification_failed", error_code="verification_failed"))
        return public_receipt(self.ledger.update(row, "verified", message_id=message_id), reused=request["operation"] == "reconcile")


def decode_request(raw: bytes) -> dict:
    def no_duplicates(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise BridgeError("invalid_request")
            result[key] = value
        return result
    try:
        return json.loads(raw.decode("utf-8"), object_pairs_hook=no_duplicates,
                          parse_constant=lambda _: (_ for _ in ()).throw(BridgeError("invalid_request")))
    except (ValueError, UnicodeError, RecursionError):
        raise BridgeError("invalid_request") from None


def read_request(connection: socket.socket) -> dict:
    deadline = time.monotonic() + READ_TIMEOUT
    content = bytearray()
    while True:
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise BridgeError("invalid_request")
        connection.settimeout(remaining)
        part = connection.recv(min(8192, MAX_REQUEST + 1 - len(content)))
        if not part:
            raise BridgeError("invalid_request")
        content.extend(part)
        if len(content) > MAX_REQUEST:
            raise BridgeError("invalid_request")
        if b"\n" in part:
            line, _, extra = bytes(content).partition(b"\n")
            if extra:
                raise BridgeError("invalid_request")
            return decode_request(line)


@contextmanager
def operation_deadline(seconds: int = OPERATION_TIMEOUT):
    def expired(_signum, _frame):
        raise OperationTimeout()
    previous = signal.signal(signal.SIGALRM, expired)
    signal.setitimer(signal.ITIMER_REAL, seconds)
    try:
        yield
    finally:
        signal.setitimer(signal.ITIMER_REAL, 0)
        signal.signal(signal.SIGALRM, previous)


class Handler(socketserver.BaseRequestHandler):
    def handle(self):
        response = rejected("invalid_request")
        request = None
        try:
            # Same user on Linux host and DDEV; no other local user can submit.
            peer = self.request.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, struct.calcsize("3i"))
            _, peer_uid, _ = struct.unpack("3i", peer)
            if peer_uid != os.getuid():
                raise BridgeError("invalid_request")
            request = read_request(self.request)
            with operation_deadline():
                response = self.server.coordinator.handle(request)
        except Exception:
            if request is not None:
                try:
                    checked = validate_request(request, self.server.coordinator.account)
                    response = self.server.coordinator._failure(checked, "unavailable")
                except Exception:
                    pass
        try:
            encoded = json.dumps(response, ensure_ascii=False, separators=(",", ":")).encode("utf-8") + b"\n"
            if len(encoded) > MAX_RESPONSE:
                encoded = json.dumps(rejected("unavailable")).encode("utf-8") + b"\n"
            self.request.settimeout(READ_TIMEOUT)
            self.request.sendall(encoded)
        except (OSError, ValueError):
            pass  # The durable receipt remains available through status.


class Server(socketserver.UnixStreamServer):
    request_queue_size = 8

    def handle_error(self, request, client_address):
        # Socket errors must not dump a request or provider traceback to logs.
        pass


def serve(socket_path: Path, coordinator: Coordinator) -> None:
    private_directory(socket_path.parent)
    if socket_path.exists() or socket_path.is_symlink():
        info = socket_path.lstat()
        if not stat.S_ISSOCK(info.st_mode) or info.st_uid != os.getuid():
            raise BridgeError("unavailable")
        probe = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        probe.settimeout(0.5)
        try:
            probe.connect(str(socket_path))
        except ConnectionRefusedError:
            socket_path.unlink()
        else:
            raise BridgeError("busy")
        finally:
            probe.close()
    server = Server(str(socket_path), Handler)
    server.coordinator = coordinator
    socket_path.chmod(0o600)
    try:
        server.serve_forever(poll_interval=0.25)
    finally:
        server.server_close()
        socket_path.unlink(missing_ok=True)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--socket", required=True, type=Path)
    parser.add_argument("--report-gen-root", required=True, type=Path)
    parser.add_argument("--account", required=True)
    parser.add_argument("--receipt-dir", required=True, type=Path)
    args = parser.parse_args()
    os.umask(0o077)
    try:
        if not all(path.is_absolute() for path in (args.socket, args.report_gen_root, args.receipt_dir)):
            raise BridgeError("invalid_request")
        if not (args.report_gen_root / "src/report_gen/gmail.py").is_file() or not (args.report_gen_root / ".local").is_dir():
            raise BridgeError("unavailable")
        coordinator = Coordinator(args.report_gen_root, args.account, args.receipt_dir)
        serve(args.socket, coordinator)
    except KeyboardInterrupt:
        return 0
    except Exception:
        print("The local Gmail draft bridge could not run. Check its private configuration.", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
