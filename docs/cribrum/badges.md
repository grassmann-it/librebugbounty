# Cribrum badges and case selection

Cases show rounded badges in inventory, workbasket, detail, review and export
preview. A badge states the finding and its certainty, for example
`SQL injection · suspected`. Its separate evidence action opens the corresponding
detail or observation. Unknown results remain available without an invented
security classification. DE/EN wording is presentation; stable codes drive queries.

The same case may have confirmed XSS, an observed SQL error and suspected SQL
injection. These are separate assertions. A claim does not by itself qualify a
recipient report component. Missing or stale evaluation is displayed explicitly;
historical/shadow information does not become a fresh positive badge. Manual
conflicts and report selection remain distinct from the detected assertion.

New semantic review context is shown once per case. Existing XSS is not labelled
new merely because an SQL hint was added. Opening a badge does not acknowledge
review or release a WAF hold. A later interpretation of retained bytes preserves
the original capture time.

## Filters and ordering

Inventory, workbasket, saved views, export selection and the corresponding CLIs
share the following dimensions. Existing workflow/scope restrictions still apply.

| HTTP query | Meaning |
|---|---|
| `claim=sql_injection&claim_state=suspected` | Both values match the same claim |
| `cribrum[]=sql_injection&cribrum[]=stack_trace` | Any selected canonical assertion; other facets combine with AND |
| `extra_findings=yes` / `no` | Has / has no distinct additional non-XSS assertion |
| `new_findings=yes` / `no` | Has / has no unresolved semantic or WAF review context |
| `cribrum_freshness=current`, `stale`, `missing` | Stored report projection freshness |
| `cribrum_history=1` | Explicitly include historical assertion membership |
| `report_kind=…` | Existing type/purity projection, independent of declared finding type |
| `sort=default` | Existing newest-first order; workbasket keeps due-date priority |
| `sort=additional_first` | Cases with additional assertions first |
| `sort=additional_count` | Most distinct additional assertions first |
| `sort=cribrum_changed` | Last semantic change first, unknown times last |

Sorting precedes pagination, with deterministic tie-breaking. Rules, matcher hits
and duplicated facts do not inflate the additional count. Re-saving an unchanged
interpretation does not renew its semantic-change timestamp. A first import with
no earlier comparable state has no invented change time.

Badge links, pagination, saved views and export links preserve the selection.
Statistics disclose overlapping groups and link to the exact counted scope.
URL-only exports retain selection and order; use a state/report export when the
structured interpretation is needed.

Examples (local commands; no target requests):

```sh
php bin/console app:finding:list --cribrum=sql_injection --extra-findings=yes --sort=additional_first
php bin/console app:export:json --claim=sql_injection --claim-state=suspected --sort=additional_count
report-gen lbb queue --db /path/to/app.sqlite --claim sql_injection --claim-state suspected --sort additional_first --explain-excluded
```

`--cribrum` is repeatable. CLI options also include `--report-kind`,
`--new-findings`, `--cribrum-freshness` and `--cribrum-history`. Domain groups sort
using their best matching member and preserve each member's interpretation.

## report-gen integration

The structured `behavior-report-v2` handoff is authoritative. The author and
reviewer receive its overview before model/author choice, including qualified
components, internal hints and unqualified claims. An unqualified suspicion is
labelled with an open evidence basis; it does not acquire recipient-report
eligibility. `--explain-excluded` can show a fully validated excluded projection's
notice without allowing its import or generation. Invalid/stale sources retain
their exclusion reason rather than becoming current reports.

Selected qualified additions belong in the combined report with their original
certainty. A revision-bound coverage checklist binds source, draft version and
mail bytes. The reviewer explicitly acknowledges each member's checklist hash.
Source decisions carry exclusions; the author cannot silently invent them.
Changed evidence makes an old XSS-only draft require revision. Structural checks
complement independent editorial review; internal codes and hashes stay out of
recipient prose and original PoCs remain unchanged.

PHP and Python execute shared generated selection contracts. Regenerate the
Python badge catalog/query artifact after changing the catalog or SQL helpers:

```sh
php /path/to/R_GEN/tools/export_cribrum_contract.php /path/to/ui > /path/to/R_GEN/src/report_gen/cribrum_catalog.json
cp docs/cribrum/new-findings-query-v1.json /path/to/R_GEN/src/report_gen/new_findings_query.json
```

Run the PHP selection/SQLite-portability tests and Python contract-parity tests
after regeneration. The current SQL avoids correlated outer-column references in
nested `ORDER BY`, including SQLite 3.46 used by the PHP 8.3 development runtime.

## Installation and retained evidence

Apply the report schema migration in the intended source after a verified backup.
Source adoption does not activate a candidate detector pack or enroll historical
cases into the fixed XSS cohort. Existing claim badges remain visible without a
scan. Missing report projections are explicit rather than treated as negative
results. Use a scoped `app:report:refresh --finding=ID --dry-run` before updating
selected local projections; refresh does not classify or contact a target.

See [the handoff contract](contracts-v2.md) and [activation guide](activation.md)
for source identity, evidence qualification and cohort/pack activation boundaries.
