# Backup and recovery

LibreBugBounty stores its SQLite database and screenshot artifacts separately.
Back up both together to retain cases, notes, contacts, assessments, and evidence
history. JSON and ZIP reporting exports do not contain the complete history.
If the optional Gmail connector is enabled, preserve its private host receipt
database as well: it records submitted text and remote draft IDs.
Writing profiles, historical example emails and Hunter lookup reservations are
part of the main application database. These remain private backup contents.

The bundled backup tool requires Python 3.11 or newer. Creating a snapshot also
requires Docker access; list, prune, verify and restore work without Docker.
Run it on the host from the project directory.

## Create a consistent snapshot

```bash
python3 bin/backup-local.py create --rulepacks var/cribrum/packs
```

The default sources are `storage/database/app.sqlite` and `storage/artifacts`.
The optional `--rulepacks` argument also includes your private Cribrum pack
directory. Pass an existing directory, including an empty one; if you do not use
private packs, omit the argument. Private packs are not discovered automatically.
The host Gmail receipt database at `storage/gmail-drafts/gmail-drafts.sqlite3`
is included automatically when present. Use `--gmail-receipts` for a custom
path. An explicitly supplied missing file, invalid permissions or an unreadable
receipt database causes an error; it is not silently omitted.
The command briefly pauses this project's running DDEV containers, creates a
SQLite snapshot including committed WAL data, and copies the artifact tree.
Keep any additional host processes that write to those files stopped during
the snapshot. Containers paused by the tool are resumed before its verification
step; containers that were already paused stay paused.

Each snapshot is written outside the checkout:

```text
~/.local/share/librebugbounty/backups/<timestamp>-<id>/
  database.sqlite
  artifacts/
  manifest.json
  rulepacks/          # only when --rulepacks was supplied
  gmail-drafts.sqlite3 # when the host Gmail receipt database was included
```

The manifest records database contents, artifact checksums, and available,
missing or unsupported references from evidence, retest screenshots, screenshot
jobs and HTTP observation artifacts (`http_observation.artifact_path`). The tool
validates a separate restoration before reporting success. Incomplete snapshots
retain a `.incomplete` suffix. Creating a backup never replaces or deletes
existing snapshots, and the live database is never restored over.
When included, private rulepacks have their own file sizes and SHA-256 checksums
in the manifest and are copied and checked under the same writer pause. Keep
host pack editors stopped during backup, just like other host writers.
Stop the Gmail bridge and other host mail writers too. The application and host
SQLite databases are copied sequentially, without a shared atomic transaction.

Backups have private directory and file permissions. To write somewhere else,
choose a new directory or an existing private directory with mode `0700`:

```bash
python3 bin/backup-local.py create --destination /absolute/path/to/private-backups
```

Keep a copy on a separate disk or other backup destination if it should survive
loss of the machine's storage. Automatic scheduling and retention are not
configured by the application.

## List and explicitly prune snapshots

```bash
python3 bin/backup-local.py list
python3 bin/backup-local.py prune --keep 5 --dry-run
# Review the selected paths, then explicitly execute the same retention policy:
python3 bin/backup-local.py prune --keep 5
```

All three commands accept `--destination /absolute/path/to/private-backups`.
Listing reports verified snapshots newest first, with creation time, artifact
and rulepack counts and total file bytes (database, manifest, artifacts, optional
rulepacks, Gmail receipts and legacy SQLite sidecars when present). Ordering uses the manifest's
parsed `created_at` timestamp, newest first, including fractional seconds and
timezone offsets. Equal timestamps use descending path order as a stable tie
breaker. Invalid or timezone-less timestamps are skipped and retained.
Pruning keeps the newest N verified snapshots and selects the older ones.
`--keep` is required and must be a non-negative integer; `--keep 0` explicitly
selects every verified snapshot. A keep count above the inventory size deletes
nothing. No other command automatically prunes snapshots.

`--dry-run` reports selected and retained paths, selected bytes and skipped
entries without changing files. The command without `--dry-run` deletes the
selection and reports it. It recomputes the selection each time; stop concurrent
backup jobs and changes to the backup directory while reviewing and pruning.

Only immediate child directories with the tool's completed timestamp/ID name,
supported manifest and successful full verification qualify. Incomplete,
corrupt, unrecognized, symlinked or unexpectedly extended snapshots are skipped,
reported and retained for manual inspection; they do not count toward N.
Empty SQLite WAL and regular shared-memory sidecars left by older verification
runs are accepted, but a nonempty WAL is retained for manual inspection.
All selected snapshots are revalidated before deletion starts. Filesystem errors
can interrupt deletion after earlier selected snapshots have been removed;
inspect the directory and rerun `list` before retrying.

## Custom installation paths

The tool does not read `.env` or DDEV overrides. If you changed the database,
artifact directory, or DDEV project name, pass the corresponding values:

```bash
python3 bin/backup-local.py create \
  --project your-ddev-project \
  --database /absolute/path/to/app.sqlite \
  --artifacts /absolute/path/to/artifacts \
  --rulepacks /absolute/path/to/private-cribrum-packs \
  --gmail-receipts /absolute/path/to/gmail-drafts.sqlite3 \
  --destination /absolute/path/to/private-backups
```

Use host filesystem paths here, even if the application's environment variables
use different paths inside the container.

If DDEV and every other database/artifact writer are already stopped, use:

```bash
python3 bin/backup-local.py create --offline
```

Offline mode refuses to proceed while this project's DDEV containers are
running. It does not stop host writers for you.

## Verify a snapshot

Replace `/absolute/path/to/snapshot` with a completed snapshot directory:

```bash
python3 bin/backup-local.py verify /absolute/path/to/snapshot
```

Verification checks SQLite integrity, saved table contents, and artifact
checksums, plus rulepack checksums when present. Older snapshots without the
optional rulepack manifest remain valid and restore without a `rulepacks/`
directory. It does not pause or restart DDEV and does not change live data.
When included, Gmail receipts receive SHA-256, size and SQLite integrity checks.
Older snapshots without Gmail receipts remain supported.
Missing references reported in the manifest were already missing when the
snapshot was created; verification cannot recreate those files.

## Read-only artifact audit

```bash
ddev exec php bin/console app:artifacts:audit
```

The audit reads the configured live database and artifact directory. It reports
stored file counts and bytes, unique referenced paths, missing or unavailable
referenced files, and possible orphan counts and bytes. References include
evidence, retest screenshots, screenshot jobs and HTTP observations, including
historical records. Repeated references to the same logical path count once.
Byte totals cover readable files; unavailable sizes are counted separately.
The command returns a nonzero status for missing files, possible orphans or
unavailable sizes. An empty, consistent store succeeds with zero totals.

The audit never deletes files or changes records. A possible orphan is a review
candidate, not permission to delete it. Back up and investigate references
before any manual cleanup. Snapshot pruning affects only the selected backup
directories, never live artifacts.

## Restore into a new directory

```bash
python3 bin/backup-local.py restore /absolute/path/to/snapshot storage/recovery-check
```

The destination must not exist. The command validates the snapshot, creates
`database.sqlite`, `artifacts/`, and `manifest.json` inside that new directory,
plus `rulepacks/` and `gmail-drafts.sqlite3` when included, and validates the copy again. It refuses to
overwrite an existing directory, including the application's current storage.

This is also a recovery rehearsal: your running installation keeps using its
original database and artifacts. The restore command does not switch the
application, launch workers, or apply migrations. Using a restored copy as an
installation requires an explicit configuration change to `DATABASE_URL` and
`EVIDENCE_STORAGE_DIR`; keep both pointed at the same restored snapshot.
Private packs are restored under the recovery directory, never over the active
`var/cribrum/packs`. With writers stopped, explicitly copy the recovered packs
into the intended installation's pack directory if needed. Preserve the verified
snapshot as the recovery source and keep private pack contents out of Git.

Gmail receipts restore as `gmail-drafts.sqlite3` beside `database.sqlite`, with
mode `0600` inside the private `0700` recovery directory. With the bridge and
other writers stopped, explicitly place the recovered host database in its
configured receipt directory (normally `storage/gmail-drafts`). The restore
command never overwrites the live bridge database. Preserve both application
and host receipts; an old snapshot cannot undo newer Gmail drafts. Reconcile
operations since that snapshot before resuming draft creation. Keep receipts
and submitted messages out of Git and ordinary cache cleanup.

### Use the restored copy with DDEV

Run `ddev stop` before switching storage. In `.ddev/config.yaml`, update the two
existing entries under `web_environment` to point at the restored working copy.
For the `storage/recovery-check` example above, use these container paths:

```yaml
web_environment:
  - DATABASE_URL=sqlite:////var/www/html/storage/recovery-check/database.sqlite
  - EVIDENCE_STORAGE_DIR=/var/www/html/storage/recovery-check/artifacts
```

Keep the other `web_environment` entries in place. DDEV explicitly supplies
these variables, so changing `.env` alone does not switch this installation.

Use the application version that matches the snapshot when rehearsing a
rollback, then run `ddev start`. Normal startup applies pending migrations and
starts background workers. Check your cases and images before continuing work.
The restored directory is now a working copy and may change; keep the original
verified snapshot as your recovery source.

## Interrupted backups

An ordinary error, Ctrl+C, or SIGTERM runs the container-resume cleanup. If Docker
itself fails, the tool identifies containers that still need to be unpaused.
A killed process or host outage can leave them paused; inspect the project
containers before starting another snapshot. Preserve `.incomplete` directories
as failed attempts, and create a new snapshot after the cause is resolved.
