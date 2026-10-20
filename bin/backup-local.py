#!/usr/bin/env python3
"""Private SQLite/artifact snapshots; never restore over an existing directory."""

import argparse
import contextlib
import hashlib
import json
import os
import re
from pathlib import Path, PurePosixPath
import shutil
import signal
import sqlite3
import subprocess
import tempfile
import time
from datetime import datetime, timezone
from uuid import uuid4


def digest(path):
    with path.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()


def open_readonly(path, immutable=False):
    suffix = "?mode=ro" + ("&immutable=1" if immutable else "")
    return sqlite3.connect(path.resolve().as_uri() + suffix, uri=True, timeout=5)


def traversal_error(error):
    # os.walk otherwise silently skips unreadable directories. A partial tree
    # must never become a successful snapshot or pass restore validation.
    raise error


def tree_manifest(root):
    result = {}
    for parent, directories, filenames in os.walk(root, followlinks=False, onerror=traversal_error):
        for name in directories + filenames:
            path = Path(parent) / name
            if path.is_symlink():
                raise RuntimeError("Artifact symlinks are not supported; nothing was removed.")
        for name in filenames:
            path = Path(parent) / name
            if not path.is_file():
                raise RuntimeError("Artifact storage contains a non-regular file.")
            result[path.relative_to(root).as_posix()] = {
                "size": path.stat().st_size,
                "sha256": digest(path),
            }
    return result


def copy_artifacts(source, target):
    target.mkdir(mode=0o700)
    for parent, directories, filenames in os.walk(source, followlinks=False, onerror=traversal_error):
        destination = target / Path(parent).relative_to(source)
        for name in directories:
            (destination / name).mkdir(mode=0o700)
        for name in filenames:
            shutil.copyfile(Path(parent) / name, destination / name)
            (destination / name).chmod(0o600)


def database_summary(path, immutable=False):
    with contextlib.closing(open_readonly(path, immutable)) as connection:
        if connection.execute("PRAGMA integrity_check").fetchall() != [("ok",)]:
            raise RuntimeError("SQLite integrity check failed.")
        schema = connection.execute(
            "SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name"
        ).fetchall()
        tables = {}
        for (table,) in connection.execute(
            "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
        ):
            quoted = '"' + table.replace('"', '""') + '"'
            checksum = hashlib.sha256()
            count = 0
            # App tables are ordinary rowid tables. Keep every column, including
            # notes, timestamps, IDs and metadata, without printing record values.
            for row in connection.execute(f"SELECT * FROM {quoted} ORDER BY rowid"):
                checksum.update(json.dumps(row, ensure_ascii=True, default=lambda value: {
                    "bytes": value.hex()
                }).encode("ascii") + b"\n")
                count += 1
            tables[table] = {"rows": count, "sha256": checksum.hexdigest()}
        return {
            "schema_sha256": hashlib.sha256(json.dumps(schema).encode()).hexdigest(),
            "tables": tables,
            "foreign_key_violations": len(connection.execute("PRAGMA foreign_key_check").fetchall()),
        }


def reference_summary(database, artifact_manifest):
    result = {"available": 0, "missing": 0, "unsupported_path": 0}
    with contextlib.closing(open_readonly(database, immutable=True)) as connection:
        tables = {row[0] for row in connection.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        for table, column in (
            ("evidence", "file_path"),
            ("retest_run", "screenshot_path"),
            ("screenshot_job", "screenshot_path"),
            ("http_observation", "artifact_path"),
        ):
            if table not in tables:
                continue
            for (raw,) in connection.execute(f"SELECT {column} FROM {table} WHERE {column} IS NOT NULL"):
                path = raw.replace("\\", "/")
                if path.startswith("storage/artifacts/"):
                    path = path[len("storage/artifacts/"):]
                if not path or PurePosixPath(path).is_absolute() or ".." in path.split("/") or ":" in path:
                    result["unsupported_path"] += 1
                else:
                    result["available" if path in artifact_manifest else "missing"] += 1
    return result


def docker(*arguments):
    return subprocess.check_output(["docker", *arguments], text=True, timeout=20).strip()


@contextlib.contextmanager
def paused_project(project, offline=False):
    """Freeze all project containers, preserving containers paused beforehand."""
    paused = []
    previous_alarm = signal.getsignal(signal.SIGALRM)

    def timed_out(signum, frame):
        raise RuntimeError("Snapshot exceeded the 60 second pause limit; retry with writers stopped.")

    signal.signal(signal.SIGALRM, timed_out)
    signal.alarm(60)
    try:
        ids = docker("ps", "-q", "--filter", f"label=com.ddev.site-name={project}").split()
        if offline and ids:
            raise RuntimeError("--offline requires all DDEV project containers to be stopped.")
        if not offline and not ids:
            raise RuntimeError("No running DDEV project found; use --offline only with all writers stopped.")
        for container in ids:
            state = json.loads(docker("inspect", "--format", "{{json .State}}", container))
            if not state["Paused"]:
                # Register first, so interruption immediately after Docker pauses
                # a container cannot leave it permanently frozen.
                paused.append(container)
                docker("pause", container)
        yield
    finally:
        signal.alarm(0)
        signal.signal(signal.SIGALRM, previous_alarm)
        # A second Ctrl-C/SIGTERM must not interrupt recovery halfway through.
        handlers = {kind: signal.signal(kind, signal.SIG_IGN) for kind in (signal.SIGINT, signal.SIGTERM)}
        failures = []
        try:
            for container in reversed(paused):
                try:
                    state = json.loads(docker("inspect", "--format", "{{json .State}}", container))
                    if state["Paused"]:
                        docker("unpause", container)
                except (subprocess.SubprocessError, OSError):
                    failures.append(container)
        finally:
            for kind, handler in handlers.items():
                signal.signal(kind, handler)
        if failures:
            raise RuntimeError("Could not unpause containers; run docker unpause for: " + " ".join(failures))


def online_backup(database, destination):
    deadline = time.monotonic() + 15

    def progress(status, remaining, total):
        if time.monotonic() > deadline:
            raise RuntimeError("SQLite snapshot timed out (possibly an in-flight transaction); retry backup.")

    with contextlib.closing(open_readonly(database)) as source:
        with contextlib.closing(sqlite3.connect(destination)) as target:
            source.backup(target, pages=256, progress=progress, sleep=0.05)
    destination.chmod(0o600)


def validate(snapshot):
    manifest = json.loads((snapshot / "manifest.json").read_text())
    if (snapshot / "artifacts").is_symlink() or not (snapshot / "artifacts").is_dir():
        raise RuntimeError("Artifact directory is missing from the snapshot.")
    if digest(snapshot / "database.sqlite") != manifest["database_sha256"]:
        raise RuntimeError("Database checksum differs from the manifest.")
    if tree_manifest(snapshot / "artifacts") != manifest["artifacts"]:
        raise RuntimeError("Artifact files differ from the manifest.")
    if "rulepacks" in manifest:
        packs = snapshot / "rulepacks"
        if packs.is_symlink() or not packs.is_dir() or tree_manifest(packs) != manifest["rulepacks"]:
            raise RuntimeError("Rulepack files differ from the manifest or the directory is missing.")
    if database_summary(snapshot / "database.sqlite", immutable=True) != manifest["database"]:
        raise RuntimeError("Restored table contents differ from the source snapshot.")
    return manifest


def restore(snapshot, destination):
    manifest = validate(snapshot)
    destination.mkdir(mode=0o700)  # Explicitly fails if any target already exists.
    shutil.copyfile(snapshot / "database.sqlite", destination / "database.sqlite")
    (destination / "database.sqlite").chmod(0o600)
    copy_artifacts(snapshot / "artifacts", destination / "artifacts")
    if "rulepacks" in manifest:
        copy_artifacts(snapshot / "rulepacks", destination / "rulepacks")
    shutil.copyfile(snapshot / "manifest.json", destination / "manifest.json")
    (destination / "manifest.json").chmod(0o600)
    return validate(destination)


def create_snapshot(database, artifacts, destination, project, offline=False, rulepacks=None):
    if database.is_symlink() or artifacts.is_symlink() or not database.is_file() or not artifacts.is_dir():
        raise RuntimeError("Database and artifacts must exist and must not be symlinks.")
    if destination.resolve().is_relative_to(artifacts.resolve()):
        raise RuntimeError("Backup destination must be outside the artifact directory.")
    if rulepacks is not None:
        if rulepacks.is_symlink() or not rulepacks.is_dir():
            raise RuntimeError("Rulepack directory must exist and must not be a symlink.")
        if destination.resolve().is_relative_to(rulepacks.resolve()):
            raise RuntimeError("Backup destination must be outside the rulepack directory.")
    destination.mkdir(parents=True, exist_ok=True, mode=0o700)
    if destination.is_symlink() or destination.stat().st_mode & 0o077:
        raise RuntimeError("Choose a private backup directory (mode 0700), without a symlink.")
    name = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ") + "-" + uuid4().hex[:8]
    staging = destination / (name + ".incomplete")
    staging.mkdir(mode=0o700)
    with paused_project(project, offline):
        files_before = tree_manifest(artifacts)
        packs_before = tree_manifest(rulepacks) if rulepacks is not None else None
        source_summary = database_summary(database)
        online_backup(database, staging / "database.sqlite")
        copy_artifacts(artifacts, staging / "artifacts")
        if rulepacks is not None:
            copy_artifacts(rulepacks, staging / "rulepacks")
            if tree_manifest(rulepacks) != packs_before or tree_manifest(staging / "rulepacks") != packs_before:
                raise RuntimeError("Rulepacks changed during backup; incomplete snapshot retained for diagnosis.")
        if tree_manifest(artifacts) != files_before or tree_manifest(staging / "artifacts") != files_before:
            raise RuntimeError("Artifacts changed during backup; incomplete snapshot retained for diagnosis.")
        if database_summary(staging / "database.sqlite", immutable=True) != source_summary:
            raise RuntimeError("Database changed during backup; retry without concurrent writers.")
    manifest = {
        "format": 1,
        "created_at": datetime.now(timezone.utc).isoformat(),
        "database_sha256": digest(staging / "database.sqlite"),
        "database": source_summary,
        "artifacts": files_before,
        "references": reference_summary(staging / "database.sqlite", files_before),
    }
    if packs_before is not None:
        manifest["rulepacks"] = packs_before
    (staging / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
    (staging / "manifest.json").chmod(0o600)
    # Exercise restoration into a second, independent directory before publishing.
    with tempfile.TemporaryDirectory(prefix="lbb-restore-") as temporary:
        restore(staging, Path(temporary) / "restored")
    completed = destination / name
    staging.rename(completed)
    return completed, manifest


def snapshot_inventory(destination):
    """Only completed, verified snapshots are eligible for explicit retention."""
    if destination.is_symlink() or not destination.is_dir():
        raise RuntimeError("Backup destination must be an existing directory without a symlink.")
    snapshots, skipped = [], []
    for path in sorted(destination.iterdir(), reverse=True):
        try:
            if not re.fullmatch(r"\d{8}T\d{6}Z-[0-9a-f]{8}", path.name):
                raise RuntimeError("not a completed snapshot name")
            manifest = retention_manifest(path)
            snapshots.append({"path": path, "created_at": manifest["created_at"],
                              "artifact_files": len(manifest["artifacts"]),
                              "rulepack_files": len(manifest.get("rulepacks", {})),
                              "bytes": sum(entry.stat().st_size for entry in path.iterdir() if entry.is_file())
                              + sum(entry["size"] for entry in manifest["artifacts"].values())
                              + sum(entry["size"] for entry in manifest.get("rulepacks", {}).values())})
        except (RuntimeError, OSError, ValueError, KeyError, TypeError, sqlite3.Error) as error:
            skipped.append({"path": str(path), "reason": str(error)})
    snapshots.sort(key=lambda entry: (snapshot_created_at(entry["created_at"]), str(entry["path"])), reverse=True)
    return snapshots, skipped


def snapshot_created_at(value):
    try:
        timestamp = datetime.fromisoformat(value)
        if timestamp.tzinfo is None or timestamp.utcoffset() is None:
            raise ValueError("timezone is required")
        return timestamp.astimezone(timezone.utc)
    except (TypeError, ValueError, OverflowError) as error:
        raise RuntimeError("invalid snapshot created_at timestamp; an ISO timestamp with timezone is required") from error


def retention_manifest(path):
    if path.is_symlink() or not path.is_dir():
        raise RuntimeError("snapshot must be a directory without a symlink")
    names = {entry.name for entry in path.iterdir()}
    required = {"database.sqlite", "artifacts", "manifest.json"}
    # Older verify runs may have created SQLite sidecars. Never discard a WAL
    # containing data; immutable validation intentionally reads the main DB only.
    if not required <= names or names - required - {"database.sqlite-wal", "database.sqlite-shm", "rulepacks"}:
        raise RuntimeError("unexpected or missing snapshot entries; retained for manual inspection")
    if any(entry.is_symlink() for entry in path.iterdir()):
        raise RuntimeError("snapshot symlinks are not supported")
    for name in names - required - {"rulepacks"}:
        if not (path / name).is_file() or (name.endswith("-wal") and (path / name).stat().st_size != 0):
            raise RuntimeError("unexpected SQLite sidecar contents; retained for manual inspection")
    manifest = validate(path)
    if ("rulepacks" in manifest) != ("rulepacks" in names):
        raise RuntimeError("unexpected rulepack directory; retained for manual inspection")
    if manifest.get("format") != 1 or not isinstance(manifest.get("created_at"), str):
        raise RuntimeError("unsupported snapshot manifest")
    snapshot_created_at(manifest["created_at"])
    return manifest


def prune_snapshots(destination, keep, dry_run=False):
    if keep < 0:
        raise RuntimeError("--keep must be a non-negative integer.")
    snapshots, skipped = snapshot_inventory(destination)
    selected = snapshots[keep:]
    # Validate the whole plan again before the first deletion. Unknown files,
    # symlinks and damaged snapshots must never be swept up by retention.
    if not dry_run:
        for entry in selected:
            retention_manifest(entry["path"])
        for entry in selected:
            shutil.rmtree(entry["path"])
    return {"dry_run": dry_run, "keep": keep,
            "retained": [str(entry["path"]) for entry in snapshots[:keep]],
            "selected": [str(entry["path"]) for entry in selected],
            "bytes": sum(entry["bytes"] for entry in selected), "skipped": skipped}


def main():
    os.umask(0o077)

    def interrupted(signum, frame):
        raise KeyboardInterrupt("Backup interrupted; unpausing project containers.")

    signal.signal(signal.SIGTERM, interrupted)
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="action", required=True)
    create = commands.add_parser("create", help="Pause DDEV writers, snapshot, resume and test restoration")
    project_root = Path(__file__).resolve().parent.parent
    create.add_argument("--project", default="librebugbounty")
    create.add_argument("--database", type=Path, default=project_root / "storage/database/app.sqlite")
    create.add_argument("--artifacts", type=Path, default=project_root / "storage/artifacts")
    create.add_argument("--rulepacks", type=Path, help="Also snapshot private Cribrum packs, e.g. var/cribrum/packs")
    create.add_argument("--destination", type=Path, default=Path.home() / ".local/share/librebugbounty/backups")
    create.add_argument("--offline", action="store_true", help="Assert all host writers are stopped; DDEV must be stopped")
    check = commands.add_parser("verify", help="Validate a previously created snapshot")
    check.add_argument("snapshot", type=Path)
    recover = commands.add_parser("restore", help="Restore into a NEW directory only; never replaces live data")
    recover.add_argument("snapshot", type=Path)
    recover.add_argument("destination", type=Path)
    default_backups = Path.home() / ".local/share/librebugbounty/backups"
    listing = commands.add_parser("list", help="List verified snapshots newest first, and retained unrecognized entries")
    listing.add_argument("--destination", type=Path, default=default_backups)
    prune = commands.add_parser("prune", help="Explicitly delete older verified snapshots; preview with --dry-run")
    prune.add_argument("--destination", type=Path, default=default_backups)
    prune.add_argument("--keep", type=int, required=True, help="Keep N newest verified snapshots (0 selects all)")
    prune.add_argument("--dry-run", action="store_true", help="Show selection without deleting anything")
    args = parser.parse_args()
    if args.action == "prune" and args.keep < 0:
        parser.error("--keep must be a non-negative integer")
    try:
        if args.action == "list":
            snapshots, skipped = snapshot_inventory(args.destination)
            print(json.dumps({"snapshots": [{**entry, "path": str(entry["path"])} for entry in snapshots],
                              "skipped": skipped}, indent=2))
            return 0
        if args.action == "prune":
            print(json.dumps(prune_snapshots(args.destination, args.keep, args.dry_run), indent=2))
            return 0
        if args.action == "create":
            snapshot, manifest = create_snapshot(args.database, args.artifacts, args.destination, args.project,
                                                 args.offline, args.rulepacks)
            print(f"Backup and restore validation succeeded: {snapshot}")
        elif args.action == "verify":
            manifest = validate(args.snapshot)
            print("Backup verified.")
        else:
            manifest = restore(args.snapshot, args.destination)
            print(f"Restored and verified separate copy: {args.destination}")
        print(json.dumps({"tables": {name: entry["rows"] for name, entry in manifest["database"]["tables"].items()},
                          "artifact_files": len(manifest["artifacts"]),
                          "rulepack_files": len(manifest.get("rulepacks", {})), "references": manifest["references"]}))
        return 0
    except (RuntimeError, OSError, ValueError, KeyError, TypeError, sqlite3.Error, subprocess.SubprocessError, KeyboardInterrupt) as error:
        print(f"Backup operation failed: {error}", file=__import__("sys").stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
