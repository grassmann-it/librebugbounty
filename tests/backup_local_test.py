import contextlib
import importlib.util
import io
import json
from pathlib import Path
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch


spec = importlib.util.spec_from_file_location("backup_local", Path(__file__).resolve().parents[1] / "bin/backup-local.py")
backup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(backup)


class BackupTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="lbb-backup-test-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.database = self.root / "source.sqlite"
        self.artifacts = self.root / "artifacts"
        self.artifacts.mkdir()
        (self.artifacts / "example.png").write_bytes(b"fixture-image")
        self.connection = sqlite3.connect(self.database)
        self.addCleanup(self.connection.close)
        self.connection.executescript("""
            PRAGMA journal_mode=WAL;
            CREATE TABLE finding (id TEXT PRIMARY KEY, notes TEXT);
            INSERT INTO finding VALUES ('fixture', 'Keep the manual note');
            CREATE TABLE evidence (id TEXT PRIMARY KEY, file_path TEXT);
            INSERT INTO evidence VALUES ('present', 'storage/artifacts/example.png');
            INSERT INTO evidence VALUES ('old-missing', 'storage/artifacts/missing.png');
            CREATE TABLE screenshot_job (id TEXT PRIMARY KEY, screenshot_path TEXT);
            INSERT INTO screenshot_job VALUES ('captured', 'storage/artifacts/example.png');
        """)

    def snapshot(self, rulepacks=None, gmail_receipts=None):
        with patch.object(backup, "paused_project", return_value=contextlib.nullcontext()):
            return backup.create_snapshot(self.database, self.artifacts, self.root / "backups", "fixture",
                                          rulepacks=rulepacks, gmail_receipts=gmail_receipts)

    def create_receipts(self, path=None):
        path = path or self.root / "private-gmail/gmail-drafts.sqlite3"
        path.parent.mkdir(parents=True, mode=0o700)
        connection = sqlite3.connect(path)
        self.addCleanup(connection.close)
        connection.executescript("""
            PRAGMA journal_mode=WAL;
            CREATE TABLE receipt (attempt_id TEXT PRIMARY KEY, state TEXT, body TEXT) WITHOUT ROWID;
            INSERT INTO receipt VALUES ('fake-attempt', 'unknown', 'Private fixture mail body');
        """)
        for suffix in ("", "-wal", "-shm"):
            Path(str(path) + suffix).chmod(0o600)
        return path

    def test_online_backup_preserves_wal_records_artifacts_and_missing_references(self):
        self.assertTrue(Path(str(self.database) + "-wal").exists())
        snapshot, manifest = self.snapshot()
        self.assertEqual({"available": 2, "missing": 1, "unsupported_path": 0}, manifest["references"])
        restored = self.root / "restored"
        backup.restore(snapshot, restored)
        with contextlib.closing(sqlite3.connect(restored / "database.sqlite")) as connection:
            self.assertEqual(("Keep the manual note",), connection.execute("SELECT notes FROM finding").fetchone())
        self.assertEqual(b"fixture-image", (restored / "artifacts/example.png").read_bytes())
        self.assertNotIn("rulepacks", manifest)
        self.assertNotIn("gmail_receipts", manifest)
        self.assertFalse((restored / "rulepacks").exists())
        self.assertFalse((restored / "gmail-drafts.sqlite3").exists())
        self.assertEqual(0o700, snapshot.stat().st_mode & 0o777)
        self.assertEqual(0o600, (snapshot / "database.sqlite").stat().st_mode & 0o777)
        self.assertEqual(0o600, (snapshot / "artifacts/example.png").stat().st_mode & 0o777)

    def test_restore_refuses_existing_directory(self):
        snapshot, _ = self.snapshot()
        with self.assertRaises(FileExistsError):
            backup.restore(snapshot, self.root)
        self.assertEqual(b"fixture-image", (self.artifacts / "example.png").read_bytes())

    def test_corrupt_artifact_or_database_is_rejected(self):
        snapshot, _ = self.snapshot()
        (snapshot / "artifacts/example.png").write_bytes(b"changed")
        with self.assertRaisesRegex(RuntimeError, "Artifact files differ"):
            backup.validate(snapshot)
        snapshot, _ = self.snapshot()
        (snapshot / "database.sqlite").write_bytes(b"invalid")
        with self.assertRaisesRegex(RuntimeError, "Database checksum differs"):
            backup.validate(snapshot)

    def test_observation_references_survive_verify_and_restore(self):
        self.connection.executescript("""
            CREATE TABLE http_observation (id TEXT PRIMARY KEY, artifact_path TEXT);
            INSERT INTO http_observation VALUES ('available', 'storage/artifacts/observation.json');
            INSERT INTO http_observation VALUES ('missing', 'missing-observation.json');
            INSERT INTO http_observation VALUES ('unsafe', '../outside.json');
            INSERT INTO http_observation VALUES ('none', NULL);
        """)
        (self.artifacts / "observation.json").write_bytes(b'{"rawHtml":"fixture"}')
        snapshot, manifest = self.snapshot()
        self.assertEqual({"available": 3, "missing": 2, "unsupported_path": 1}, manifest["references"])
        self.assertEqual(manifest, backup.validate(snapshot))
        restored = self.root / "restored-observations"
        self.assertEqual(manifest, backup.restore(snapshot, restored))
        self.assertEqual((self.artifacts / "observation.json").read_bytes(),
                         (restored / "artifacts/observation.json").read_bytes())
        self.assertEqual(manifest["references"], backup.reference_summary(
            restored / "database.sqlite", backup.tree_manifest(restored / "artifacts")))

    def test_private_rulepack_bytes_verify_restore_and_retention_roundtrip(self):
        packs = self.root / "private-packs"
        (packs / "nested").mkdir(parents=True)
        private_bytes = b'private-pack\x00\xff\n{"rules":["local-only"]}\n'
        (packs / "nested/local.pack").write_bytes(private_bytes)
        snapshot, manifest = self.snapshot(rulepacks=packs)
        self.assertEqual(backup.tree_manifest(packs), manifest["rulepacks"])
        self.assertEqual(private_bytes, (snapshot / "rulepacks/nested/local.pack").read_bytes())
        self.assertEqual(0o600, (snapshot / "rulepacks/nested/local.pack").stat().st_mode & 0o777)
        verify = self.cli("verify", snapshot)
        self.assertEqual(0, verify.returncode, verify.stderr)
        restored = self.root / "restored-private-packs"
        result = self.cli("restore", snapshot, restored)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(private_bytes, (restored / "rulepacks/nested/local.pack").read_bytes())
        self.assertEqual(manifest, backup.validate(restored))
        inventory, skipped = backup.snapshot_inventory(snapshot.parent)
        self.assertEqual([], skipped)
        self.assertEqual(sum(p.stat().st_size for p in snapshot.rglob('*') if p.is_file()), inventory[0]["bytes"])
        plan = backup.prune_snapshots(snapshot.parent, 0, dry_run=True)
        self.assertEqual([str(snapshot)], plan["selected"])
        self.assertTrue((packs / "nested/local.pack").exists())

    def test_create_cli_accepts_optional_rulepacks(self):
        packs = self.root / "private-packs"
        packs.mkdir()
        (packs / "local.pack").write_bytes(b"private-cli-pack")
        output = io.StringIO()
        arguments = [backup.__file__, "create", "--database", str(self.database),
                     "--artifacts", str(self.artifacts), "--rulepacks", str(packs),
                     "--destination", str(self.root / "backups"), "--offline"]
        with patch.object(sys, "argv", arguments), contextlib.redirect_stdout(output), \
                patch.object(backup, "__file__", str(self.root / "bin/backup-local.py")), \
                patch.object(backup.signal, "signal"), patch.object(backup.os, "umask"), \
                patch.object(backup, "paused_project", return_value=contextlib.nullcontext()):
            self.assertEqual(0, backup.main())
        snapshots, skipped = backup.snapshot_inventory(self.root / "backups")
        self.assertEqual([], skipped)
        self.assertEqual(1, len(snapshots))
        self.assertEqual(1, snapshots[0]["rulepack_files"])
        self.assertEqual(b"private-cli-pack", (snapshots[0]["path"] / "rulepacks/local.pack").read_bytes())
        self.assertEqual(1, json.loads(output.getvalue().splitlines()[-1])["rulepack_files"])

    def test_gmail_receipts_preserve_wal_restore_privately_and_count_in_retention(self):
        receipts = self.create_receipts()
        self.assertGreater(Path(str(receipts) + "-wal").stat().st_size, 0)
        snapshot, manifest = self.snapshot(gmail_receipts=receipts)
        saved = snapshot / "gmail-drafts.sqlite3"
        self.assertEqual({"size": saved.stat().st_size, "sha256": backup.digest(saved)},
                         manifest["gmail_receipts"])
        self.assertEqual(0o600, saved.stat().st_mode & 0o777)
        before = backup.tree_manifest(snapshot)
        verified = self.cli("verify", snapshot)
        self.assertEqual(0, verified.returncode, verified.stderr)
        self.assertEqual(1, json.loads(verified.stdout.splitlines()[-1])["gmail_receipt_files"])
        self.assertNotIn("Private fixture mail body", verified.stdout)
        self.assertEqual(before, backup.tree_manifest(snapshot))
        restored = self.root / "restored-gmail"
        self.assertEqual(manifest, backup.restore(snapshot, restored))
        self.assertEqual(0o700, restored.stat().st_mode & 0o777)
        self.assertEqual(0o600, (restored / "gmail-drafts.sqlite3").stat().st_mode & 0o777)
        with contextlib.closing(backup.open_readonly(restored / "gmail-drafts.sqlite3", immutable=True)) as connection:
            self.assertEqual(("fake-attempt", "unknown", "Private fixture mail body"),
                             connection.execute("SELECT * FROM receipt").fetchone())
        inventory, skipped = backup.snapshot_inventory(snapshot.parent)
        self.assertEqual([], skipped)
        self.assertEqual(1, inventory[0]["gmail_receipt_files"])
        total = sum(path.stat().st_size for path in snapshot.rglob("*") if path.is_file())
        self.assertEqual(total, inventory[0]["bytes"])
        plan = backup.prune_snapshots(snapshot.parent, 0, dry_run=True)
        self.assertEqual(total, plan["bytes"])
        self.assertTrue(saved.exists())
        self.assertEqual([str(snapshot)], backup.prune_snapshots(snapshot.parent, 0)["selected"])
        self.assertFalse(snapshot.exists())
        self.assertTrue(receipts.exists())

    def test_create_cli_discovers_default_gmail_receipts_and_honors_explicit_path(self):
        default = self.root / "storage/gmail-drafts/gmail-drafts.sqlite3"
        for name in ("absent-default", "present-default", "explicit", "missing-explicit"):
            with self.subTest(name=name):
                extra = []
                if name == "present-default":
                    self.create_receipts(default)
                elif name == "explicit":
                    extra = ["--gmail-receipts", str(self.create_receipts())]
                elif name == "missing-explicit":
                    extra = ["--gmail-receipts", str(self.root / "absent.sqlite3")]
                output, errors = io.StringIO(), io.StringIO()
                arguments = [backup.__file__, "create", "--database", str(self.database),
                             "--artifacts", str(self.artifacts), "--destination", str(self.root / name),
                             "--offline", *extra]
                with patch.object(sys, "argv", arguments), contextlib.redirect_stdout(output), \
                        contextlib.redirect_stderr(errors), \
                        patch.object(backup, "__file__", str(self.root / "bin/backup-local.py")), \
                        patch.object(backup.signal, "signal"), patch.object(backup.os, "umask"), \
                        patch.object(backup, "paused_project", return_value=contextlib.nullcontext()):
                    self.assertEqual(int(name == "missing-explicit"), backup.main(), errors.getvalue())
                if name == "missing-explicit":
                    self.assertIn("must exist", errors.getvalue())
                    self.assertFalse((self.root / name).exists())
                else:
                    expected = int(name != "absent-default")
                    self.assertEqual(expected, json.loads(output.getvalue().splitlines()[-1])["gmail_receipt_files"])
                    inventory, skipped = backup.snapshot_inventory(self.root / name)
                    self.assertEqual([], skipped)
                    self.assertEqual(expected, inventory[0]["gmail_receipt_files"])

    def test_gmail_source_rejects_missing_nonprivate_and_symlink_paths(self):
        receipts = self.create_receipts()
        for mode in (0o644, 0o666):
            receipts.chmod(mode)
            with self.assertRaisesRegex(RuntimeError, "private file"):
                self.snapshot(gmail_receipts=receipts)
        receipts.chmod(0o600)
        receipts.parent.chmod(0o755)
        with self.assertRaisesRegex(RuntimeError, "private file"):
            self.snapshot(gmail_receipts=receipts)
        receipts.parent.chmod(0o700)
        link = self.root / "linked.sqlite3"
        link.symlink_to(receipts)
        linked_parent = self.root / "linked-directory"
        linked_parent.symlink_to(receipts.parent, target_is_directory=True)
        dangling = self.root / "dangling.sqlite3"
        dangling.symlink_to(self.root / "absent.sqlite3")
        for path in (link, linked_parent / receipts.name, dangling):
            with self.subTest(path=path.name), self.assertRaisesRegex(RuntimeError, "symlink"):
                self.snapshot(gmail_receipts=path)
        for path in (self.root / "absent.sqlite3", receipts.parent):
            with self.subTest(path=path.name), self.assertRaisesRegex(RuntimeError, "regular file"):
                self.snapshot(gmail_receipts=path)
        self.assertFalse((self.root / "backups").exists())

    def test_gmail_source_rejects_symlink_or_nonprivate_sidecars(self):
        receipts = self.create_receipts()
        wal = Path(str(receipts) + "-wal")
        wal.chmod(0o644)
        with self.assertRaisesRegex(RuntimeError, "sidecars must be private"):
            self.snapshot(gmail_receipts=receipts)
        wal.chmod(0o600)
        shm = Path(str(receipts) + "-shm")
        original = shm.with_name("original-shm")
        shm.rename(original)
        shm.symlink_to(original)
        try:
            with self.assertRaisesRegex(RuntimeError, "sidecars must be regular"):
                self.snapshot(gmail_receipts=receipts)
        finally:
            shm.unlink()
            original.rename(shm)
        self.assertFalse((self.root / "backups").exists())

    def test_gmail_receipt_corruption_or_untracked_data_refuses_restore_and_prune(self):
        receipts = self.create_receipts()
        for damage in ("bytes", "size", "missing", "untracked", "symlink", "wal", "path", "public"):
            with self.subTest(damage=damage):
                snapshot, manifest = self.snapshot(gmail_receipts=receipts)
                saved = snapshot / "gmail-drafts.sqlite3"
                if damage == "bytes":
                    saved.write_bytes(b"broken sqlite")
                elif damage == "size":
                    manifest["gmail_receipts"]["size"] += 1
                elif damage == "missing":
                    saved.unlink()
                elif damage == "untracked":
                    del manifest["gmail_receipts"]
                elif damage == "symlink":
                    saved.unlink()
                    saved.symlink_to(receipts)
                elif damage == "wal":
                    (snapshot / "gmail-drafts.sqlite3-wal").write_bytes(b"unaccounted receipt")
                elif damage == "path":
                    manifest["gmail_receipts"]["path"] = "../private-gmail/gmail-drafts.sqlite3"
                elif damage == "public":
                    saved.chmod(0o644)
                (snapshot / "manifest.json").write_text(json.dumps(manifest))
                self.assertEqual(1, self.cli("verify", snapshot).returncode)
                destination = self.root / ("invalid-gmail-restore-" + damage)
                self.assertEqual(1, self.cli("restore", snapshot, destination).returncode)
                self.assertFalse(destination.exists())
                plan = backup.prune_snapshots(snapshot.parent, 0)
                self.assertEqual([], plan["selected"])
                self.assertTrue(snapshot.exists())

    def test_gmail_receipt_integrity_is_checked_even_with_matching_hash(self):
        snapshot, manifest = self.snapshot(gmail_receipts=self.create_receipts())
        saved = snapshot / "gmail-drafts.sqlite3"
        saved.write_bytes(b"not a sqlite database")
        manifest["gmail_receipts"] = {"size": saved.stat().st_size, "sha256": backup.digest(saved)}
        (snapshot / "manifest.json").write_text(json.dumps(manifest))
        self.assertEqual(1, self.cli("verify", snapshot).returncode)
        self.assertEqual([], backup.prune_snapshots(snapshot.parent, 0)["selected"])

    def test_gmail_restore_and_backup_reject_symlink_destination_parents_before_writing(self):
        receipts = self.create_receipts()
        snapshot, _ = self.snapshot(gmail_receipts=receipts)
        target = self.root / "destination-target"
        target.mkdir(mode=0o700)
        link = self.root / "linked-destination"
        link.symlink_to(target, target_is_directory=True)
        with self.assertRaisesRegex(RuntimeError, "symlink"):
            backup.restore(snapshot, link / "restored")
        with self.assertRaisesRegex(RuntimeError, "symlink"):
            backup.create_snapshot(self.database, self.artifacts, link / "backups", "fixture",
                                   gmail_receipts=receipts)
        self.assertEqual([], list(target.iterdir()))

    def test_create_cli_rejects_dangling_default_receipt_directory(self):
        storage = self.root / "storage"
        storage.mkdir()
        (storage / "gmail-drafts").symlink_to(self.root / "absent-private-directory", target_is_directory=True)
        output, errors = io.StringIO(), io.StringIO()
        arguments = [backup.__file__, "create", "--database", str(self.database),
                     "--artifacts", str(self.artifacts), "--destination", str(self.root / "backups"), "--offline"]
        with patch.object(sys, "argv", arguments), contextlib.redirect_stdout(output), \
                contextlib.redirect_stderr(errors), \
                patch.object(backup, "__file__", str(self.root / "bin/backup-local.py")), \
                patch.object(backup.signal, "signal"), patch.object(backup.os, "umask"):
            self.assertEqual(1, backup.main())
        self.assertIn("symlink", errors.getvalue())
        self.assertFalse((self.root / "backups").exists())

    def test_default_receipts_do_not_hide_access_errors_or_orphaned_sidecars(self):
        receipts = self.root / "missing-gmail.sqlite3"
        with patch.object(Path, "lstat", side_effect=PermissionError("Cannot inspect private receipts")):
            with self.assertRaisesRegex(PermissionError, "Cannot inspect"):
                backup.default_receipt_database(receipts)
        self.assertIsNone(backup.default_receipt_database(receipts))
        for suffix in ("-wal", "-shm"):
            with self.subTest(suffix=suffix):
                sidecar = Path(str(receipts) + suffix)
                sidecar.write_bytes(b"unaccounted receipt")
                try:
                    with self.assertRaisesRegex(RuntimeError, "without the database"):
                        backup.default_receipt_database(receipts)
                finally:
                    sidecar.unlink()

    def test_rulepack_corruption_refuses_verify_restore_and_pruning(self):
        packs = self.root / "private-packs"
        packs.mkdir()
        (packs / "local.pack").write_bytes(b"private")
        snapshot, _ = self.snapshot(rulepacks=packs)
        (snapshot / "rulepacks/local.pack").write_bytes(b"corrupt")
        self.assertEqual(1, self.cli("verify", snapshot).returncode)
        restored = self.root / "invalid-restore"
        self.assertEqual(1, self.cli("restore", snapshot, restored).returncode)
        self.assertFalse(restored.exists())
        plan = backup.prune_snapshots(snapshot.parent, 0)
        self.assertEqual([], plan["selected"])
        self.assertEqual(1, len(plan["skipped"]))
        self.assertTrue(snapshot.exists())

    def test_empty_rulepack_directory_is_preserved(self):
        packs = self.root / "empty-packs"
        packs.mkdir()
        snapshot, manifest = self.snapshot(rulepacks=packs)
        self.assertEqual({}, manifest["rulepacks"])
        restored = self.root / "restored-empty-packs"
        backup.restore(snapshot, restored)
        self.assertTrue((restored / "rulepacks").is_dir())

    def test_rulepack_source_must_exist_without_symlinks_or_nested_destination(self):
        with self.assertRaisesRegex(RuntimeError, "must exist"):
            self.snapshot(rulepacks=self.root / "absent-packs")
        packs = self.root / "private-packs"
        packs.mkdir()
        link = self.root / "linked-packs"
        link.symlink_to(packs, target_is_directory=True)
        with self.assertRaisesRegex(RuntimeError, "symlink"):
            self.snapshot(rulepacks=link)
        with self.assertRaisesRegex(RuntimeError, "outside the rulepack"):
            backup.create_snapshot(self.database, self.artifacts, packs / "backups", "fixture", rulepacks=packs)
        (packs / "linked.pack").symlink_to(self.database)
        with self.assertRaisesRegex(RuntimeError, "symlinks"):
            self.snapshot(rulepacks=packs)
        self.assert_only_incomplete_snapshot()

    def test_rulepack_changes_do_not_publish_snapshot(self):
        packs = self.root / "private-packs"
        packs.mkdir()
        original = backup.tree_manifest
        calls = 0

        def changing_manifest(root):
            nonlocal calls
            if root == packs:
                calls += 1
                if calls == 2:
                    (packs / "new.pack").write_bytes(b"concurrent-change")
            return original(root)

        with patch.object(backup, "tree_manifest", side_effect=changing_manifest):
            with self.assertRaisesRegex(RuntimeError, "Rulepacks changed"):
                self.snapshot(rulepacks=packs)
        self.assert_only_incomplete_snapshot()

    def retention_snapshots(self):
        paths = []
        for day in (1, 3, 2):
            path, _ = self.snapshot()
            target = path.parent / f"202610{day:02d}T120000Z-1234abcd"
            path.rename(target)
            manifest_path = target / "manifest.json"
            manifest = json.loads(manifest_path.read_text())
            manifest["created_at"] = f"2026-10-{day:02d}T12:00:00+00:00"
            manifest_path.write_text(json.dumps(manifest))
            paths.append(target)
        return sorted(paths, reverse=True)

    def cli(self, *arguments):
        return subprocess.run([sys.executable, str(Path(backup.__file__)), *map(str, arguments)],
                              text=True, capture_output=True)

    def test_list_preview_and_explicit_prune_keep_newest_without_automatic_deletion(self):
        paths = self.retention_snapshots()
        destination = self.root / "backups"
        before = backup.tree_manifest(destination)
        listing = self.cli("list", "--destination", destination)
        self.assertEqual(0, listing.returncode, listing.stderr)
        inventory = json.loads(listing.stdout)
        self.assertEqual([str(path) for path in paths], [entry["path"] for entry in inventory["snapshots"]])
        self.assertTrue(all(entry["bytes"] > 0 for entry in inventory["snapshots"]))
        preview = self.cli("prune", "--destination", destination, "--keep", 1, "--dry-run")
        self.assertEqual(0, preview.returncode, preview.stderr)
        plan = json.loads(preview.stdout)
        self.assertEqual([str(path) for path in paths[1:]], plan["selected"])
        self.assertEqual(before, backup.tree_manifest(destination))
        result = self.cli("prune", "--destination", destination, "--keep", 1)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(plan["selected"], json.loads(result.stdout)["selected"])
        self.assertEqual([paths[0]], list(destination.iterdir()))
        backup.validate(paths[0])
        backup.restore(paths[0], self.root / "after-prune-restore")

    def test_same_second_backups_keep_actual_newest_despite_inverted_uuid_order(self):
        paths = []
        for suffix, timestamp in (("ffffffff", "2026-10-20T12:00:00.100000+00:00"),
                                  ("00000000", "2026-10-20T12:00:00.900000+00:00")):
            path, manifest = self.snapshot()
            target = path.parent / f"20261020T120000Z-{suffix}"
            path.rename(target)
            manifest["created_at"] = timestamp
            (target / "manifest.json").write_text(json.dumps(manifest))
            paths.append(target)
        older, newer = paths
        destination = older.parent
        before = backup.tree_manifest(destination)
        listing = self.cli("list", "--destination", destination)
        self.assertEqual(0, listing.returncode, listing.stderr)
        self.assertEqual([str(newer), str(older)],
                         [entry["path"] for entry in json.loads(listing.stdout)["snapshots"]])
        preview = backup.prune_snapshots(destination, 1, dry_run=True)
        self.assertEqual([str(newer)], preview["retained"])
        self.assertEqual([str(older)], preview["selected"])
        self.assertEqual(before, backup.tree_manifest(destination))
        result = backup.prune_snapshots(destination, 1)
        self.assertEqual(preview["selected"], result["selected"])
        self.assertFalse(older.exists())
        self.assertTrue(newer.exists())
        backup.validate(newer)

    def test_timestamp_offsets_are_compared_as_instants_with_stable_path_ties(self):
        paths = self.retention_snapshots()
        # First two represent the same instant; the third looks later as text
        # but is an hour earlier after applying its timezone offset.
        for path, timestamp in zip(paths, ("2026-10-20T12:00:00Z", "2026-10-20T14:00:00+02:00",
                                           "2026-10-20T15:00:00+04:00")):
            manifest_path = path / "manifest.json"
            manifest = json.loads(manifest_path.read_text())
            manifest["created_at"] = timestamp
            manifest_path.write_text(json.dumps(manifest))
        inventory, skipped = backup.snapshot_inventory(self.root / "backups")
        self.assertEqual([], skipped)
        self.assertEqual(paths, [entry["path"] for entry in inventory])

    def test_invalid_or_timezone_less_creation_timestamp_is_never_pruned(self):
        path, manifest = self.snapshot()
        for timestamp in ("invalid", "2026-02-30T12:00:00Z", "2026-10-20T12:00:00", "2026-10-20"):
            with self.subTest(timestamp=timestamp):
                manifest["created_at"] = timestamp
                (path / "manifest.json").write_text(json.dumps(manifest))
                before = backup.tree_manifest(path.parent)
                with self.assertRaisesRegex(RuntimeError, "invalid snapshot created_at"):
                    backup.retention_manifest(path)
                result = backup.prune_snapshots(path.parent, 0)
                self.assertEqual([], result["selected"])
                self.assertEqual([str(path)], [entry["path"] for entry in result["skipped"]])
                self.assertEqual(before, backup.tree_manifest(path.parent))

    def test_prune_retains_incomplete_unknown_corrupt_and_symlink_entries(self):
        paths = self.retention_snapshots()
        destination = self.root / "backups"
        (paths[0] / "artifacts/example.png").write_bytes(b"corrupt")
        (paths[1] / "unexpected.txt").write_text("Keep manually added data")
        incomplete = destination / "20261004T120000Z-1234abcd.incomplete"
        incomplete.mkdir()
        unknown = destination / "manual-notes"
        unknown.write_text("Retain me")
        link = destination / "20261005T120000Z-1234abcd"
        link.symlink_to(paths[2], target_is_directory=True)
        inventory, skipped = backup.snapshot_inventory(destination)
        self.assertEqual([paths[2]], [entry["path"] for entry in inventory])
        self.assertEqual(5, len(skipped))
        plan = backup.prune_snapshots(destination, 0)
        self.assertEqual([str(paths[2])], plan["selected"])
        self.assertTrue(paths[0].exists())
        self.assertTrue((paths[1] / "unexpected.txt").exists())
        self.assertTrue(incomplete.exists())
        self.assertEqual("Retain me", unknown.read_text())
        self.assertTrue(link.is_symlink())

    def test_prune_requires_valid_explicit_keep_and_existing_destination(self):
        paths = self.retention_snapshots()
        destination = self.root / "backups"
        before = backup.tree_manifest(destination)
        for arguments in ([], ["--keep", "-1"], ["--keep", "abc"]):
            result = self.cli("prune", "--destination", destination, *arguments)
            self.assertEqual(2, result.returncode)
        self.assertEqual([], backup.prune_snapshots(destination, 99)["selected"])
        self.assertEqual(before, backup.tree_manifest(destination))
        link = self.root / "linked-backups"
        link.symlink_to(destination, target_is_directory=True)
        for invalid in (link, self.root / "absent"):
            self.assertEqual(1, self.cli("prune", "--destination", invalid, "--keep", 0).returncode)
        self.assertEqual(before, backup.tree_manifest(destination))

    def test_prune_revalidates_entire_selection_before_deleting(self):
        paths = self.retention_snapshots()
        original = backup.snapshot_inventory

        def changed_after_inventory(destination):
            inventory = original(destination)
            (paths[-1] / "unexpected.txt").write_text("new data")
            return inventory

        with patch.object(backup, "snapshot_inventory", side_effect=changed_after_inventory):
            with self.assertRaisesRegex(RuntimeError, "unexpected"):
                backup.prune_snapshots(self.root / "backups", 0)
        self.assertTrue(all(path.exists() for path in paths))

    def test_legacy_sqlite_sidecars_are_read_only_and_nonempty_wal_is_retained(self):
        paths = self.retention_snapshots()
        (paths[0] / "database.sqlite-wal").write_bytes(b"")
        (paths[0] / "database.sqlite-shm").write_bytes(b"legacy-shared-memory")
        (paths[1] / "database.sqlite-wal").write_bytes(b"unreviewed-wal-data")
        destination = self.root / "backups"
        before = backup.tree_manifest(destination)
        inventory, skipped = backup.snapshot_inventory(destination)
        self.assertEqual([paths[0], paths[2]], [entry["path"] for entry in inventory])
        self.assertEqual([str(paths[1])], [entry["path"] for entry in skipped])
        self.assertEqual(sum(p.stat().st_size for p in paths[0].rglob('*') if p.is_file()), inventory[0]["bytes"])
        backup.prune_snapshots(destination, 0, dry_run=True)
        self.assertEqual(before, backup.tree_manifest(destination))

    def test_symlink_is_rejected(self):
        (self.artifacts / "link").symlink_to(self.database)
        with self.assertRaisesRegex(RuntimeError, "symlinks"):
            self.snapshot()

    @staticmethod
    def unreadable_walk(root, *, followlinks=False, onerror=None):
        yield str(root), ["unreadable"], []
        # os.walk normally ignores scandir errors unless an onerror callback is
        # supplied. Simulate that contract without relying on the test user's UID.
        if onerror is not None:
            onerror(PermissionError("Cannot list artifact subdirectory"))

    def assert_only_incomplete_snapshot(self):
        snapshots = list((self.root / "backups").iterdir())
        self.assertEqual(1, len(snapshots))
        self.assertTrue(snapshots[0].name.endswith(".incomplete"))
        self.assertFalse((snapshots[0] / "manifest.json").exists())

    def test_manifest_traversal_error_does_not_publish_snapshot(self):
        with patch.object(backup.os, "walk", side_effect=self.unreadable_walk):
            with self.assertRaisesRegex(PermissionError, "Cannot list"):
                self.snapshot()
        self.assert_only_incomplete_snapshot()

    def test_copy_traversal_error_does_not_publish_snapshot(self):
        original_walk = backup.os.walk
        calls = 0

        def fail_during_copy(*args, **kwargs):
            nonlocal calls
            calls += 1
            if calls == 2:
                return self.unreadable_walk(*args, **kwargs)
            return original_walk(*args, **kwargs)

        with patch.object(backup.os, "walk", side_effect=fail_during_copy):
            with self.assertRaisesRegex(PermissionError, "Cannot list"):
                self.snapshot()
        self.assert_only_incomplete_snapshot()

    def test_unpause_runs_after_error_and_preserves_previously_paused_container(self):
        paused = {"second"}

        def docker(*args):
            if args[0] == "ps":
                return "first second"
            if args[0] == "inspect":
                return json.dumps({"Paused": args[-1] in paused})
            if args[0] == "pause":
                paused.add(args[-1])
            if args[0] == "unpause":
                paused.remove(args[-1])
            return ""

        with patch.object(backup, "docker", side_effect=docker) as mock:
            with self.assertRaises(KeyboardInterrupt):
                with backup.paused_project("fixture"):
                    raise KeyboardInterrupt()
            mock.assert_any_call("pause", "first")
            mock.assert_any_call("unpause", "first")
            self.assertNotIn(unittest.mock.call("unpause", "second"), mock.call_args_list)

    def test_partial_pause_failure_recovers_already_paused_container(self):
        paused = set()

        def docker(*args):
            if args[0] == "ps":
                return "first second"
            if args[0] == "inspect":
                return json.dumps({"Paused": args[-1] in paused})
            if args[0] == "pause" and args[-1] == "second":
                raise subprocess.CalledProcessError(1, "docker pause second")
            if args[0] == "pause":
                paused.add(args[-1])
            if args[0] == "unpause":
                paused.remove(args[-1])
            return ""

        with patch.object(backup, "docker", side_effect=docker):
            with self.assertRaises(subprocess.CalledProcessError):
                with backup.paused_project("fixture"):
                    self.fail("Incomplete pause must not start a backup")
        self.assertEqual(set(), paused)

    def test_online_backup_has_a_time_limit(self):
        with patch.object(backup.time, "monotonic", side_effect=[0, 16]):
            with self.assertRaisesRegex(RuntimeError, "timed out"):
                backup.online_backup(self.database, self.root / "timeout.sqlite")

    def test_offline_refuses_running_containers(self):
        with patch.object(backup, "docker", return_value="first"):
            with self.assertRaisesRegex(RuntimeError, "stopped"):
                with backup.paused_project("fixture", offline=True):
                    self.fail("Must not take an unprotected online snapshot")


if __name__ == "__main__":
    unittest.main()
