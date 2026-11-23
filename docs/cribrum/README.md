# Cribrum contribution and handoff contracts

These public, versioned documents describe the in-repository rule library and its
finding/report adapters. Start with the [contributor guide](contributing.md), then
read the [evidence and snapshot contract](contracts-v2.md) and
[activation and rollback procedure](activation.md).
For the case UI, shared filters/sorting and report-gen coverage, read
[badges and case selection](badges.md).
For per-finding approval and case-specific rejection during image Review, see
[Review selection and rule feedback](review-selection.md).
The [source-reading receipt contract](contracts-v2.md#source-reading-receipts)
describes local AI review coordination and its separate case-detail status.
The [2026-10-27 retrospective](retrospective-2026-10-27.md) explains repeated
misses, the limits of current checks and prioritized open work.

Contributions currently arrive as reviewed GitHub pull requests. Registered
detectors are ordinary PHP project code; this delivery does not provide a
separately installable external plugin system or a standalone library package.
The existing project remains GPL-3.0-or-later; see [LICENSE](../../LICENSE).
No license for a future extracted package is assigned here.

The examples use invented `.invalid` hosts and synthetic content. Running the
local fixture workflow needs neither production data nor private evaluation
reports, a legacy cohort manifest, browser capture, Gmail, or target requests.
The library contract does not authorize production activation.

Current engine **1.3.8** adds request values decoded from the URL path, a
bounded multi-line response carrier for statements a debug page echoes across
several lines, and one suspicion helper for a request value that a disclosed
statement retains verbatim and unescaped inside its own quoting. Path values
stay out of the core context needles and qualify only source-bound carriers.
See the [engine 1.3.8 rule card](rule-cards/disclosed-query-input-1.3.8.md).

The preceding engine **1.3.7** adds native serialized-alert and localized Access
expression carriers, plus native PHP fatal and database-result warning
observations. SQLi suspicion still needs the helper's qualified input
relationship; result/resource failures alone stay runtime errors. Old captures
without request pins remain historical even when an offline replay now recognizes
their diagnostics. See the
[engine 1.3.7 rule card](rule-cards/native-carriers-and-runtime-1.3.7.md).

The preceding engine **1.3.6** adds a separate core suspicion path for a complete,
byte-length-checked Phalcon PHQL parser diagnostic with one distinctive request
value at an unquoted `ORDER BY` operand. It preserves raw markup and evidence
spans, and explicitly distinguishes ORM parsing from native database execution.
It creates neither typed SQL facts nor SQLi report eligibility. See the
[engine 1.3.6 rule card](rule-cards/phql-input-suspicion-1.3.6.md).

The preceding engine **1.3.5** extends the core suspicion hint to canonical native
MySQL/MariaDB syntax messages without mandatory SQLSTATE and with bounded
ellipsis-keyword tails, retaining long exact unique binding and partial-input /
missing-query uncertainty. A separate PDO diagnostic/query fallback handles
supported ordered append-array inputs without inferring structural SQLi or a
database vendor. Candidate **1.5.2**, active-mode
structural detection and report policy are unchanged. See the
[engine 1.3.5 rule card](rule-cards/native-sql-suspicion-1.3.5.md).
The preceding engine **1.3.4** introduced the narrower SQLSTATE `42000` / error
`1064` core hint; its original boundaries and scoped verification remain in the
[engine 1.3.4 rule card](rule-cards/mysql-syntax-suspicion-1.3.4.md).
The preceding engine **1.3.3** / candidate **1.5.2** correction adds narrow
`DB ERR` plus concrete SQL statement recognition without treating the wrapper as SQLi. See the
[1.5.2 correction card](rule-cards/http-response-triage-1.5.2.md).
The preceding [1.5.1 correction card](rule-cards/http-response-triage-1.5.1.md)
describes query/context false-positive fixes, successful-status diagnostic observations and
UTF-8 coverage corrections. The [1.5.0 card](rule-cards/http-response-triage-1.5.0.md)
retains the preceding query, frame, runtime, catalogue and WAF changes. These
cards do not authorize live detector activation.
