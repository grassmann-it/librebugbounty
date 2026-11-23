# Publishing LibreBugBounty releases

Release notes are stored here so the published GitHub text can be reviewed
alongside the code. For this release, use tag `v2.2.0`, title
`Cribrum - v2.2.0`, and the body in `v2.2.0.md`. On 2026-11-23 the
maintainer requested the release after extended hands-on testing and explicitly
accepted remaining classifier inaccuracies. This supersedes the earlier
local-only preparation restriction. Publication still requires passing release
checks and CI for the publishing commit.

## Classifier release decision

The release ships **engine 1.3.8** and the explicitly selectable
**behavior-triage 1.5.2 candidate pack**. The maintainer's 2026-11-23 decision is
to ship with clearly disclosed limitations and invite bug reports, sanitized
examples, regex improvements and regression fixtures. It is not a claim of
complete detector coverage, measured global precision/recall, or a new full
private-corpus acceptance run.

The broader classifier review requested on 2026-10-27 remains follow-up work:
independently label retained and new source/context fixtures, investigate shared
parsing/carrier/input-association causes from the
[retrospective](../../docs/cribrum/retrospective-2026-10-27.md), and verify changed
outcomes through certainty, explanations, filters, exports and report policy.
Source-reading receipts alone do not establish classifier correctness. New
classifier or rulepack changes must recheck affected cases.

Historical scoped verification is retained separately from the public release
announcement in [the validation record](../../docs/releases/v2.2.0-validation.md).
Current release checks must identify their tested source and distinguish local
results from remote CI. Existing measurements establish only their recorded
scope; known misses and false positives remain visible release limitations.

From 2026-10-12 onward, GitHub release titles always use
`<Release-Name> - v<Version>`, for example **Cribrum - v2.2.0**.
Keep the release name first. Version 2.2.0 uses Cribrum; historical Moneta and
Conatus names remain unchanged. Do not rename historical releases as part of
an ordinary publication.

## Before publication

Confirm that the release commit contains the intended changes and that the
working tree is clean. Keep private architecture notes, databases, artifacts,
and local test or demo files outside Git. Check the application and worker
versions, changelog, upgrade guide, and release notes together.

Run the checks used by CI with PHP 8.3 and 8.4, Node.js 22 for the worker, and
Python 3.11 or later for backups. Use an isolated worker checkout for `npm ci`
if a running local instance shares its dependency directory.

```bash
ddev exec composer validate --strict --no-check-publish
ddev exec vendor/bin/phpunit
ddev exec composer audit --locked --no-interaction
npm --prefix playwright-worker ci --no-audit --no-fund
npm --prefix playwright-worker test
npm --prefix playwright-worker audit --omit=dev --audit-level=high
python3 -B -m unittest discover -s tests -p 'backup_local_test.py' -v
```

Review the successful browser acceptance for changed workflows, a fresh
DDEV installation and an isolated upgrade from v2.1.1. The 2.2.0 release adds the
claim ledger, immutable claim history and HTTP/DOM observations, plus immutable
report evidence and versioned report
projections; confirm that existing case URLs, assessments and artifact references
survive all additive schema migrations. Restart PHP and the Playwright workers
together and reload old browser tabs before checking
interactive saves. Application tests use isolated storage; never point them
at a working installation's database or artifact directory.

Keep the classifier as the release's main focus in the changelog, notes, About
and Settings. Verify core/private-pack defaults separately from the explicitly
selected `behavior-triage` 1.5.2 candidate with engine 1.3.8: candidate selection
defaults to shadow, active mode needs a complete detector/output selection, and installing source
must not activate a candidate or reclassify the inventory. Check SQL/include
suspicion, reflection versus execution, partial/failed evaluation coverage,
shared badge/filter selection, stale evidence and WAF review holds against the
exact release commit. Test scoped offline report refresh and the report-handoff
contract with synthetic fixtures before representing these paths as accepted.
For the `DB ERR` correction, verify HTTP 200 and unclosed diagnostic-container
positives alongside missing-query, request-echo and documentation/code negatives.
The generic wrapper must remain an observed unknown-vendor database error rather
than create SQLi suspicion or confirmation.
For the engine 1.3.5 core hint, verify canonical native MySQL/MariaDB context
with and without SQLSTATE, conflicting state/code exclusions, long exact input
suffixes, bounded omitted prefixes and punctuation/ellipsis-keyword tails, unique
scalar or array-leaf attribution and request-echo/documentation negatives.
Its suggestion must remain suspected with partial-input/missing-query uncertainty;
strict candidate structural facts, active-mode outputs and report qualification
must remain unchanged.
Check the separate PDO `HY000` / `20018` query-input fallback with complete-query,
ordered append-array and exact long-value attribution positives and negatives;
it must not infer a database vendor or generate structural SQLi facts.
Check the PHQL core hint with complete byte-length-checked queries, closed
`parsePHQL()` traces, exact unique `ORDER BY` input binding, namespace/placeholder
variants and raw/entity spans. Reject wrong lengths, incomplete or supplied
queries, ambiguous inputs and literal/comment/documentation lookalikes. Preserve
the distinction between an ORM parser diagnostic and a native SQL error; no typed
SQL facts, active-mode SQLi or eligible SQLi report component may be introduced.
Verify Intake duplicate receipts and their existing-case link through global
history refresh/reload without personal progress or repeated saves. With
paste-to-submit disabled, exact repeat full-URL pastes must keep one copy without
submitting; different URL and partial edits must keep their native behavior.
Verify the source-reading migration, atomic expiring reservations, hash/context
freshness and immutable/idempotent receipts. Case UI states must distinguish
reservation, completed reading, stale reading and unavailable source. Recording a
reading must leave claims, manual assessment, report qualification and activation
unchanged; reviewer labels and summaries must describe actual source inspection.
Review selection must be tested in normal and focus mode through mouse, keyboard,
swipe and native forms. Case-specific rejections must bind to exact stored facts;
new observations must not inherit an old rejection. Rule hints remain pending
until explicitly reviewed and never change detector activation.

For PoC updates, verify same-host HTTP(S)/GET validation, ambiguous URL selection,
unchanged image source and exact-URL intake deduplication. For deferred review,
verify one due attempt against the active PoC, matching native-dialog image
evidence before resumption, and failure/interruption remaining deferred without
changing the assessment. HTTP 200 or an observed dialog alone is insufficient.
Record current checks and fresh-install/upgrade results separately from historical
acceptance. Publication alone does not prove compatibility or classifier accuracy.

## Prepare without publishing

Commit and push the reviewed preparation branch, then create an unpublished draft
targeting its exact commit. Check for an existing draft first; edit it rather than
creating duplicate release records. Do not create or push the tag at this stage.

```bash
gh release create v2.2.0 \
  --draft \
  --target "$(git rev-parse HEAD)" \
  --title 'Cribrum - v2.2.0' \
  --notes-file .github/releases/v2.2.0.md
```

Use an `(unreleased)` changelog marker during preparation. At final
publication, record the actual publication date, update the verification notes,
and verify CI for the resulting release commit. If that differs from the draft's
target, update the draft target explicitly before publication.

## Publish the approved commit

Run these steps from the approved release commit after its branch has been
reviewed and pushed, and confirm that GitHub CI passed for that exact commit.
Install GitHub CLI and authenticate it with access to this repository. Creating
the tag and GitHub release publishes that commit. Use the reviewed notes file
rather than automatically generated commit messages.

```bash
git tag -a v2.2.0 -m 'Cribrum - v2.2.0'
git push origin v2.2.0
gh release edit v2.2.0 \
  --draft=false \
  --verify-tag \
  --latest \
  --target "$(git rev-parse HEAD)" \
  --title 'Cribrum - v2.2.0' \
  --notes-file .github/releases/v2.2.0.md
```

Once published, check the release title, notes, source archives, and links to
the tagged documentation. Record the publication date in the changelog for
future releases; preparation alone does not establish that date.
