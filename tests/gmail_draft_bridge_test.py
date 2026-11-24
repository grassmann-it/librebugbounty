#!/usr/bin/env python3
"""Offline Gmail bridge tests: fake auth, fake Gmail, temporary private state."""
import base64
from email.message import EmailMessage
from email.policy import SMTP
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import signal
import socket
import tempfile
import threading
import unittest
from unittest.mock import patch
import uuid


SPEC = importlib.util.spec_from_file_location("gmail_draft_bridge", Path(__file__).parents[1] / "bin/gmail-draft-bridge.py")
bridge = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(bridge)


def encode(recipient, subject, body):
    mail = EmailMessage(policy=SMTP)
    mail["To"] = recipient
    mail["Subject"] = subject
    mail["MIME-Version"] = "1.0"
    mail["Content-Type"] = 'text/plain; charset="utf-8"'
    mail["Content-Transfer-Encoding"] = "base64"
    mail.set_payload(base64.encodebytes(body.encode()).decode("ascii"))
    return mail.as_bytes()


class FakeClient:
    def __init__(self):
        self.auth_calls = self.create_calls = self.read_calls = 0
        self.auth_error = self.create_error = self.read_error = None
        self.transform = lambda value: value
        self.raw = None
        self.before_create = None

    def authenticate(self, account):
        self.auth_calls += 1
        if self.auth_error:
            raise self.auth_error

    def encode(self, recipient, subject, body):
        return encode(recipient, subject, body)

    def create(self, raw):
        self.create_calls += 1
        self.raw = raw
        if self.before_create:
            self.before_create()
        if self.create_error:
            raise self.create_error
        return {"draft_id": "draft_fixture_123"}

    def read(self, draft_id):
        self.read_calls += 1
        if self.read_error:
            raise self.read_error
        stored = {"id": draft_id, "message": {"id": "message_fixture_456", "labelIds": ["DRAFT"],
                    "raw": base64.urlsafe_b64encode(self.raw).decode("ascii")}}
        return self.transform(stored)


class BridgeTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / ".local").mkdir()
        self.client = FakeClient()
        self.writer = bridge.Coordinator(self.root, "writer@example.invalid", self.root / "receipts",
            client_factory=lambda: self.client, helper_receipt_dir=self.root / "helper")
        self.request = {
            "version": 1, "operation": "create", "account": "writer@example.invalid",
            "attempt_id": str(uuid.uuid4()), "case_key": "a" * 64, "fingerprint": "b" * 64,
            "recipient": "security@example.invalid", "subject": "Sicherheitsmeldung – Rückfrage",
            "body": "Guten Tag,\n\nOriginal-PoC:\nhttps://example.invalid/?a=%2B&b=ä+ß&LBB=1\n\nMit freundlichen Grüßen",
        }
        self.rehash()

    def rehash(self):
        self.request["content_sha256"] = bridge.content_hash(*(self.request[x] for x in ("account", "recipient", "subject", "body")))

    def status(self):
        return self.writer.handle({key: self.request[key] for key in ("version", "account", "case_key")} | {"operation": "status"})

    def reconcile(self):
        return self.writer.handle({key: self.request[key] for key in ("version", "account", "case_key", "attempt_id", "content_sha256")} | {"operation": "reconcile"})

    def test_construct_and_status_never_touch_auth_or_gmail(self):
        self.assertEqual("not_found", self.status()["error_code"])
        self.assertEqual((0, 0, 0), (self.client.auth_calls, self.client.create_calls, self.client.read_calls))
        self.assertEqual(0o700, (self.root / "receipts").stat().st_mode & 0o777)
        self.assertEqual(0o600, self.writer.ledger.path.stat().st_mode & 0o777)

    def test_create_persists_pending_before_post_and_id_before_read(self):
        self.client.before_create = lambda: self.assertEqual("pending", self.status()["state"])
        previous_read = self.client.read
        def read(ident):
            self.assertEqual(ident, self.status()["draft_id"])
            self.assertEqual("verification_failed", self.status()["state"])
            return previous_read(ident)
        self.client.read = read
        result = self.writer.handle(self.request)
        self.assertTrue(result["ok"])
        self.assertFalse(result["safe_to_retry"])
        self.assertEqual("verified", result["state"])
        self.assertEqual("message_fixture_456", result["message_id"])
        self.assertNotIn("body", result)
        self.assertNotIn(self.request["recipient"], json.dumps(result))

    def test_exact_retry_reuses_and_different_attempt_or_body_never_creates(self):
        self.writer.handle(self.request)
        self.assertTrue(self.writer.handle(self.request)["reused"])
        self.assertEqual(1, self.client.create_calls)
        self.request["attempt_id"] = str(uuid.uuid4())
        self.assertEqual("case_locked", self.writer.handle(self.request)["error_code"])
        self.request["body"] += "\nEdited"
        self.rehash()
        self.assertEqual("case_locked", self.writer.handle(self.request)["error_code"])
        self.assertEqual(1, self.client.create_calls)

    def test_unknown_create_cannot_retry_even_with_changed_content(self):
        self.client.create_error = TimeoutError("private provider details")
        first = self.writer.handle(self.request)
        self.assertEqual("uncertain", first["state"])
        self.assertFalse(first["safe_to_retry"])
        self.assertNotIn("private", json.dumps(first))
        self.client.create_error = None
        self.assertEqual("uncertain", self.reconcile()["state"])
        self.writer.handle(self.request)
        self.request["attempt_id"] = str(uuid.uuid4())
        self.request["body"] += "\nChanged"
        self.rehash()
        self.assertEqual("case_locked", self.writer.handle(self.request)["error_code"])
        self.assertEqual(1, self.client.create_calls)
        self.assertEqual(0, self.client.read_calls)

    def test_known_id_readback_failure_can_only_reconcile(self):
        self.client.read_error = TimeoutError("private response")
        first = self.writer.handle(self.request)
        self.assertEqual("verification_failed", first["state"])
        self.assertEqual("draft_fixture_123", first["draft_id"])
        self.client.read_error = None
        self.assertEqual("verified", self.reconcile()["state"])
        self.assertEqual(1, self.client.create_calls)
        self.assertEqual(2, self.client.read_calls)

    def test_crash_pending_without_id_reconcile_does_not_call_gmail(self):
        self.writer.ledger.reserve(self.request)
        self.assertEqual("uncertain", self.reconcile()["state"])
        self.assertEqual(0, self.client.auth_calls)

    def test_safe_auth_rejection_is_durable_and_requires_new_attempt(self):
        self.client.auth_error = ValueError("secret refresh error")
        first = self.writer.handle(self.request)
        self.assertEqual("rejected", first["state"])
        self.assertTrue(first["safe_to_retry"])
        self.client.auth_error = None
        self.assertEqual(first["state"], self.writer.handle(self.request)["state"])
        self.assertEqual(1, self.client.auth_calls)
        self.request["attempt_id"] = str(uuid.uuid4())
        self.assertEqual("verified", self.writer.handle(self.request)["state"])
        self.assertEqual(1, self.client.create_calls)

    def test_account_mismatch_prevents_create(self):
        self.client.auth_error = bridge.BridgeError("account_mismatch")
        result = self.writer.handle(self.request)
        self.assertEqual("account_mismatch", result["error_code"])
        self.assertTrue(result["safe_to_retry"])
        self.assertEqual(0, self.client.create_calls)

    def test_global_lock_includes_authentication(self):
        with bridge.file_lock(self.root / ".local/pipeline-gmail.lock"):
            result = self.writer.handle(self.request)
        self.assertEqual("busy", result["error_code"])
        self.assertTrue(result["safe_to_retry"])
        self.assertEqual(0, self.client.auth_calls)
        self.assertEqual("rejected", self.writer.handle(self.request)["state"])

    def test_helper_lock_includes_authentication(self):
        bridge.private_directory(self.root / "helper")
        with bridge.file_lock(self.root / "helper/.gmail.lock"):
            result = self.writer.handle(self.request)
        self.assertEqual("busy", result["error_code"])
        self.assertEqual(0, self.client.auth_calls)

    def test_reconcile_busy_or_auth_failure_never_makes_create_retry_safe(self):
        self.client.read_error = TimeoutError()
        self.writer.handle(self.request)
        with bridge.file_lock(self.root / ".local/pipeline-gmail.lock"):
            self.assertFalse(self.reconcile()["safe_to_retry"])
        self.client.auth_error = ValueError("auth failed")
        result = self.reconcile()
        self.assertFalse(result["safe_to_retry"])
        self.assertEqual("verification_failed", result["state"])
        self.assertEqual(1, self.client.create_calls)

    def test_positive_helper_receipt_blocks_case_even_after_edits(self):
        bridge.private_directory(self.root / "helper")
        raw = encode(self.request["recipient"], self.request["subject"], self.request["body"])
        ident = hashlib.sha256(self.request["account"].encode() + b"\0" + raw).hexdigest()
        (self.root / "helper" / (ident + ".json")).write_text('{"state":"pending"}')
        result = self.writer.handle(self.request)
        self.assertEqual("known_receipt", result["error_code"])
        self.assertFalse(result["safe_to_retry"])
        self.request["attempt_id"] = str(uuid.uuid4())
        self.request["body"] += "\nEdited"
        self.rehash()
        self.assertEqual("case_locked", self.writer.handle(self.request)["error_code"])
        self.assertEqual(0, self.client.create_calls)

    def test_post_create_storage_failure_preserves_known_id_and_blocks_retry(self):
        with patch.object(self.writer.ledger, "update", side_effect=OSError("disk error")):
            result = self.writer.handle(self.request)
        self.assertEqual("verification_failed", result["state"])
        self.assertEqual("draft_fixture_123", result["draft_id"])
        self.assertFalse(result["safe_to_retry"])
        self.assertEqual("pending", self.writer.handle(self.request)["state"])
        self.assertEqual(1, self.client.create_calls)

    def test_wrong_hash_extra_fields_invalid_address_and_controls_fail_before_auth(self):
        for changes in ({"content_sha256": "c" * 64}, {"token_path": "/private"},
                        {"recipient": "one@example.invalid,two@example.invalid"},
                        {"subject": "Hello\r\nBcc: bad@example.invalid"},
                        {"body": "bad\x00body"}, {"account": "other@example.invalid"},
                        {"version": True}, {"operation": "send"}):
            with self.subTest(changes=changes):
                result = self.writer.handle(self.request | changes)
                self.assertEqual("invalid_request", result["error_code"])
        self.assertEqual(0, self.client.auth_calls)

    def test_status_is_receipt_only_even_when_credentials_become_unavailable(self):
        self.writer.handle(self.request)
        self.client.auth_error = ValueError("unavailable")
        result = self.status()
        self.assertEqual("verified", result["state"])
        self.assertEqual(1, self.client.auth_calls)

    def test_reconcile_requires_original_attempt_and_content_digest(self):
        self.writer.handle(self.request)
        self.request["content_sha256"] = "d" * 64
        self.assertEqual("case_locked", self.reconcile()["error_code"])
        self.assertEqual(1, self.client.read_calls)

    def test_verification_rejects_cc_bcc_duplicate_headers_sent_wrong_subject_body(self):
        row = self.writer.ledger.reserve(self.request)
        row["draft_id"] = "draft_fixture_123"
        original = encode(self.request["recipient"], self.request["subject"], self.request["body"])
        mutations = [
            lambda raw: b"Cc: attacker@example.invalid\r\n" + raw,
            lambda raw: b"Bcc: \r\n" + raw,
            lambda raw: b"To: security@example.invalid\r\n" + raw,
            lambda raw: b"Subject: Another\r\n" + raw,
            lambda raw: encode("attacker@example.invalid", self.request["subject"], self.request["body"]),
            lambda raw: encode(self.request["recipient"], "Different", self.request["body"]),
            lambda raw: encode(self.request["recipient"], self.request["subject"], self.request["body"] + "\n"),
        ]
        for mutate in mutations:
            stored = {"id": row["draft_id"], "message": {"id": "message123", "labelIds": ["DRAFT"],
                "raw": base64.urlsafe_b64encode(mutate(original)).decode()}}
            with self.subTest(raw=mutate.__name__):
                with self.assertRaises(bridge.BridgeError):
                    bridge.verify_draft(stored, row)
        for labels in ([], ["SENT"], ["DRAFT", "SENT"]):
            stored = {"id": row["draft_id"], "message": {"id": "message123", "labelIds": labels,
                "raw": base64.urlsafe_b64encode(original).decode()}}
            with self.assertRaises(bridge.BridgeError):
                bridge.verify_draft(stored, row)

    def test_explicit_known_draft_reconcile_reverifies_all_bytes(self):
        self.writer.handle(self.request)
        original = self.client.raw
        self.client.raw = original.replace(b"security@example.invalid", b"changed@example.invalid")
        result = self.reconcile()
        self.assertEqual("verification_failed", result["state"])
        self.assertFalse(result["ok"])
        self.assertFalse(result["safe_to_retry"])


class ProtocolTests(unittest.TestCase):
    def test_json_duplicate_keys_invalid_utf8_and_nonfinite_are_rejected(self):
        for raw in (b'{"operation":"create","operation":"send"}', b'\xff', b'{"value":NaN}'):
            with self.subTest(raw=raw), self.assertRaises(bridge.BridgeError):
                bridge.decode_request(raw)

    def test_one_json_frame_and_no_second_request(self):
        left, right = socket.socketpair()
        self.addCleanup(left.close)
        self.addCleanup(right.close)
        left.sendall(b'{"version":1}\n')
        self.assertEqual({"version": 1}, bridge.read_request(right))
        left.sendall(b'{}\n{}\n')
        with self.assertRaises(bridge.BridgeError):
            bridge.read_request(right)

    def test_size_limit_and_total_read_deadline(self):
        left, right = socket.socketpair()
        self.addCleanup(left.close)
        self.addCleanup(right.close)
        with patch.object(bridge, "MAX_REQUEST", 16):
            left.sendall(b" " * 17)
            with self.assertRaises(bridge.BridgeError):
                bridge.read_request(right)
        with patch.object(bridge, "READ_TIMEOUT", 0.01):
            with self.assertRaises((bridge.BridgeError, TimeoutError)):
                bridge.read_request(right)

    def test_operation_deadline_interrupts_slow_existing_client(self):
        with self.assertRaises(bridge.OperationTimeout):
            with bridge.operation_deadline(0.01):
                signal.pause()

    def test_content_hash_contract_is_exact_utf8_with_nul_delimiters(self):
        fields = ("writer@example.invalid", "to@example.invalid", "Betreff ü", "Line1\nLine2 ä")
        self.assertEqual(hashlib.sha256(b"\0".join(x.encode() for x in fields)).hexdigest(), bridge.content_hash(*fields))

    def test_unix_socket_handler_accepts_same_uid_and_has_no_network_side_effect(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / ".local").mkdir()
            client = FakeClient()
            writer = bridge.Coordinator(root, "writer@example.invalid", root / "receipts",
                client_factory=lambda: client, helper_receipt_dir=root / "helper")
            path = root / "bridge.sock"
            server = bridge.Server(str(path), bridge.Handler)
            self.addCleanup(server.server_close)
            server.coordinator = writer
            result = []
            def call():
                with socket.socket(socket.AF_UNIX) as connection:
                    connection.settimeout(2)
                    connection.connect(str(path))
                    connection.sendall(json.dumps({"version": 1, "operation": "status", "account": "writer@example.invalid", "case_key": "a" * 64}).encode() + b"\n")
                    result.append(json.loads(connection.recv(bridge.MAX_RESPONSE)))
            thread = threading.Thread(target=call)
            thread.start()
            server.handle_request()
            thread.join(timeout=3)
            self.assertFalse(thread.is_alive())
            self.assertEqual("not_found", result[0]["error_code"])
            self.assertEqual((0, 0, 0), (client.auth_calls, client.create_calls, client.read_calls))

    def test_different_peer_uid_is_rejected_before_request_processing(self):
        class Connection:
            def getsockopt(self, *args):
                return bridge.struct.pack("3i", 123, os.getuid() + 1, 123)
            def settimeout(self, value):
                pass
            def sendall(self, value):
                self.response = json.loads(value)
        connection = Connection()
        handler = object.__new__(bridge.Handler)
        handler.request = connection
        handler.handle()
        self.assertEqual("invalid_request", connection.response["error_code"])
        self.assertFalse(connection.response["safe_to_retry"])


if __name__ == "__main__":
    unittest.main()
