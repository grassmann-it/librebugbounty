# Candidate evaluation, activation and rollback

This delivery leaves candidate detection in shadow and does not activate packs,
production migrations, cohort import, reclassification, captures, or target tests.
Installation of source code alone must not reclassify the full inventory.

1. Freeze immutable local inputs and metadata with hashes and record baseline
   engine/pack/policy versions. Use a disposable checkout and temporary database.
2. Run baseline and explicitly selected candidate offline on the same inputs.
   Check all semantic positives and negatives, unknown transformations,
   incomplete blocks, source bindings and scope/coverage cases. Preserve explain
   output and report collisions with active broad SQL suggestions.
3. Review the per-family comparison. Shadow evidence cannot open a mail gate.
   Adding a strict new rule does not fix a broad old rule that still wins an
   unsupported claim; retire or narrow that collision explicitly.
4. A later authorized rollout must name exact engine/pack/policy/schema versions,
   supported families, approved fixtures and rollback versions. The application
   wiring already routes active evidence through immutable finding/PoC/source-bound
   facts, source-validated projection and reader checks. Verify those paths in the
   intended isolated environment before enabling active mode. Merely changing `shadow` in a candidate manifest
   is not an activation mechanism. Test migrations on a copy before applying them
   to an intended deployment. Import a fixed legacy manifest only explicitly;
   never derive membership from every migration-origin claim.
5. Recompute affected current projections against the current basis and PoC.
   Retain historic snapshot bytes, fact bytes and version identifiers. A policy
   change invalidates old current decisions until recomputation; an old
   `eligible` must not survive a failed refresh. Preserve manual decisions and
   outstanding WAF review. No rollout action implies sending mail or OBB selection.

## Isolated rollout rehearsal

The application opt-in is `CRIBRUM_CANDIDATE_DIRECTORY` (empty by default) and
`CRIBRUM_CANDIDATE_MODE` (`shadow` by default; `active` requires explicit opt-in).
An empty directory setting selects no candidate packs. Use the complete reviewed
`behavior-triage` **1.5.2** candidate manifest with engine **1.3.7** (all four
detectors, including `reflection-triage-v1`) for active evaluation; an incomplete
registered detector/output
selection is rejected. These settings affect processing of observations presented
to the application. They do not enqueue a full inventory reclassification.

Engines 1.3.5 and 1.3.6 change core suspicion helpers, not the candidate manifest or
activation contract. The native MySQL/MariaDB partial-input, PDO query-input and
PHQL ORM parser/input
hints do not run as new active structural detectors, emit qualified SQLi facts or
grant report eligibility.
The strict candidate detector and report policy retain their evidence requirements.

Engine 1.3.7 adds core serialized-alert/Access helpers with the same SQL
active-mode boundary. Its PHP runtime carrier correction also affects the
context detector's observed `error_response` output in explicit active mode.
A bounded native PostgreSQL `query was:` carrier can also add query disclosure
and active `internal_information_exposed`; later request dumps supply no input
relationship. Neither path adds SQL structural evidence. The candidate manifest
stays at 1.5.2. Source-bound cause badges are presentation metadata, not facts.
See the [1.3.7 correction card](rule-cards/native-carriers-and-runtime-1.3.7.md).

From the isolated checkout, run focused tests first. `tests/bootstrap.php` creates
and removes a unique temporary SQLite database and artifact root and overrides
inherited live database/worker settings:

```sh
CRIBRUM_CANDIDATE_DIRECTORY= CRIBRUM_CANDIDATE_MODE=shadow \
  vendor/bin/phpunit tests/Cribrum tests/ReportProjectionServiceTest.php \
  tests/BehaviorPipelineAcceptanceTest.php
```

For an offline baseline/candidate rehearsal, prepare an invented local response
in a disposable directory. These commands read only that file; the context URL is
not fetched. `--capture-complete` is deliberately omitted unless completeness was
independently established:

```sh
cribrum_trial_dir=$(mktemp -d)
printf '%s' '<p>Synthetic diagnostic-free response</p>' > "$cribrum_trial_dir/response.html"
php bin/console app:cribrum:classify 'https://fixture.invalid/search?q=demo' \
  --response="$cribrum_trial_dir/response.html" --format=json \
  > "$cribrum_trial_dir/baseline.json"
php bin/console app:cribrum:classify 'https://fixture.invalid/search?q=demo' \
  --response="$cribrum_trial_dir/response.html" \
  --candidate-packs=resources/cribrum/candidates --candidate-mode=shadow \
  --format=json --explain > "$cribrum_trial_dir/shadow.json"
php bin/console app:cribrum:classify 'https://fixture.invalid/search?q=demo' \
  --response="$cribrum_trial_dir/response.html" \
  --candidate-packs=resources/cribrum/candidates --candidate-mode=active \
  --format=json --explain > "$cribrum_trial_dir/active-offline.json"
```

Retain and compare these reports with the regression corpus; this minimal smoke
input alone is not a release acceptance test. For a subsequent isolated application
rehearsal, supply `CRIBRUM_CANDIDATE_DIRECTORY` as the absolute candidate directory
and start with `CRIBRUM_CANDIDATE_MODE=shadow`. After the acceptance review, set mode
to `active` only in that disposable application instance and restart its processes
so their container configuration is rebuilt. Keep its database and artifact paths
separate. No production migration command or automatic reclassification command
is part of this procedure. Production migration/activation needs its own named
backup, tested migration and approved version set.

The report-gen instruction source is maintained at `skill/report-gen/` in R_GEN.
Review it together with the matching reader/policy version before installing it
in the skill directory actually used by the application. Preserve existing user,
ledger, contact and style instructions; a source copy alone is not installation.
Verify the installed entrypoint and reader with a fresh local synthetic probe.
Record the actual installed paths, hashes and rollback source in the deployment
record. The badge/report-gen integration delivery of 24 October includes this
installation and verification; it does not activate a candidate detector pack.

See [badges and shared case selection](badges.md) for the UI, CLI and report-gen
coverage workflow.

## Rollback

For engine 1.3.7, retain **engine 1.3.6 plus candidate 1.5.2**, including the
preceding runtime detector implementation. Reverting only the unchanged
candidate manifest cannot undo core helpers or the PHP runtime carrier change.

For the engine 1.3.6 PHQL hint, retain **engine 1.3.5 plus candidate 1.5.2**.
The unchanged candidate cannot revert the core helper by itself. Preserve the
distinction between ORM parsing and native SQL diagnostics. See the
[engine 1.3.6 rule card](rule-cards/phql-input-suspicion-1.3.6.md).

For the historical engine 1.3.5 native SQL suspicion extensions, retain **engine 1.3.4 plus
candidate 1.5.2** as the preceding runtime pair. The candidate manifest is unchanged;
reverting only its version cannot restore the preceding core helper behavior.
See the [engine 1.3.5 rule card](rule-cards/native-sql-suspicion-1.3.5.md).

For the historical engine 1.3.4 core MySQL suspicion hint, retain **engine 1.3.3 plus
candidate 1.5.2** as the preceding runtime pair. The candidate manifest is unchanged;
reverting its version cannot restore the older core helper. See the
[engine 1.3.4 rule card](rule-cards/mysql-syntax-suspicion-1.3.4.md).

For the historical engine 1.3.3 / candidate 1.5.2 `DB ERR` correction, retain the preceding **engine 1.3.2 plus
candidate 1.5.1** source pair. The
[1.5.2 correction card](rule-cards/http-response-triage-1.5.2.md) records the narrow
observed-diagnostic change. A pack-only downgrade does not restore engine behavior.

For the historical 1.5.1 correction, retain the preceding **engine 1.3.1 plus
candidate 1.5.0** source pair. The [correction card](rule-cards/http-response-triage-1.5.1.md)
records the changed detection boundaries.

For the historical 1.5.0 candidate, retain the exact secured **engine1.2.0 source/WIP plus
candidate1.4.0 pack** as the preceding runtime pair. The candidate version is not
the engine version. A pack-only downgrade leaves newer detector logic in
place and is not a semantic rollback. Retain the actual pre-change source/WIP
snapshot, not only the branch base commit, and restore affected files selectively
without discarding unrelated work. No rollback is performed by this delivery.

Rollback restores the previously approved engine and pack versions and the
compatible policy configuration. Set `CRIBRUM_CANDIDATE_DIRECTORY` to empty
and restore `CRIBRUM_CANDIDATE_MODE=shadow`, then restart the affected application
processes. Stop candidate selection first, invalidate
projections affected by the removed active semantics, and recompute using the
restored policy. Preserve historical facts/snapshots and review records; do not
rewrite old evidence or raw PoCs. A database schema rollback requires its own
compatible tested migration/backup plan, rather than deleting historical rows.
Revalidate report cases before reuse/export after either activation or rollback.

## Explicit offline report projection refresh

After the reviewed schema migration and any explicit fixed E2 cohort import,
refresh selected stored findings without requesting a browser run. This command
uses the newest retained active observation for the current PoC revision when
one exists, plus independent stored worker proof and context. It does not run the
classifier, import cohort members, enqueue captures, or contact target sites.
Newer shadow-only observations do not replace the active interpretation.

Use the isolated application's database and artifact configuration. Start with a
rollback-only preview, then remove `--dry-run` for the same reviewed selection:

```sh
php bin/console app:report:refresh --finding=FINDING_ID --dry-run
php bin/console app:report:refresh --finding=FINDING_ID
php bin/console app:report:refresh --finding=FIRST_ID --finding=SECOND_ID
php bin/console app:report:refresh --limit=100 --dry-run
```

`--limit` accepts 1–10000 and selects findings in stable ID order. Exactly one
scope is mandatory: repeatable `--finding`, bounded `--limit`, or explicit `--all`.
There is no implicit inventory refresh. `--all` deliberately requests the complete
selected local inventory; preview it with `--all --dry-run` before a separately
authorized full refresh. A dry run evaluates the actual projection and rolls back
all of its context, fact, and decision writes. Missing findings or failed evidence
validation produce a failing exit status; normal execution continues reporting
other selected findings. Review resulting gates and case kinds before report-gen
reuse or extraction; a refresh is not a mail-send approval.
