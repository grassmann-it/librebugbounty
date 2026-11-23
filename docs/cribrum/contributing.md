# Contributing rules and detectors

Cribrum is useful today and still has blind spots. Missed diagnostics, false
positives, unusual response formats and better regexes are all useful
contributions. [Open an issue](https://github.com/grassmann-it/librebugbounty/issues)
with the engine/pack version, expected result, actual result and a small sanitized
or synthetic example. A clear bug report is welcome even without a patch.

For a regex or detector change, include a matching example and a close example
that must not match. Preserve the relevant request/response relationship and
encoding, and explain whether the result is an observation or a suspicion.
Use synthetic domains and remove credentials, private URLs and personal data.
The workflow below explains how to turn that example into a tested contribution.

Submit a pull request against this repository. A useful change contains a small
rule card, a versioned candidate manifest, independently written expected results,
synthetic positive and negative fixtures, and focused offline regression tests.
Explain the observation that qualifies the result and the stronger claims it does
not establish. Preserve GPL-3.0-or-later notices and contributor attribution.

Use stable pack, rule, detector and output identifiers. Keep an identifier when
its meaning stays compatible; increment the pack version when detection changes.
A breaking meaning requires a new identifier or schema/policy version and a
migration/compatibility decision. Do not recycle a removed identifier for another
meaning. Pack filenames must equal their manifest `id` plus `.json`.

Schema 1 remains compatible with existing literal claim-suggestion rules.
[Schema 2](schemas/rule-pack-v2.schema.json) selects claim-free outputs of named,
code-registered detectors. The [example candidate](examples/contributor-triage.json)
shows SQL and reflection rules in the format. It deliberately lacks file/context
families, so it is a shadow-only partial example. The complete shipped candidate
`resources/cribrum/candidates/behavior-triage.json` is version **1.5.2** and selects
all four detectors. Registered capabilities are `sql-triage-v1`, `file-triage-v1`,
`context-triage-v1`, and `reflection-triage-v1` (`input_reflected`). A pack cannot supply a PHP filename, evaluate arbitrary
code, change report eligibility, or select a model/provider. New capabilities
need reviewed detector code and registration in the core. Schema-2 candidates
remain shadow by default; a manifest with `shadow: false` is rejected. Only
explicit runtime/CLI mode can opt into active evaluation.

The [1.5.2 correction card](rule-cards/http-response-triage-1.5.2.md) describes
the latest bounded diagnostic variant and its counterexamples; the linked
preceding cards retain the earlier candidate changes.

For example, adding a WAF vendor today is a PHP pull request against
`src/Cribrum/Detector/ContextTriageDetector.php` plus synthetic positive and negative
cases in `tests/Cribrum/ContextTriageDetectorTest.php`. Emit the existing
`coverage_limited` block fact with its vendor attribute only when the response
proves a relevant block; vendor presence alone stays `product_context`.
The UI, transition policy and report reader use those shared meanings and need
no vendor-specific branches. Schema 2 currently selects registered detector
outputs; it is not a declarative vendor-signature catalog. A separate catalog or
external plugin installation is future work, rather than a capability of the
example manifest.

Candidate packs belong outside the automatic active pack directories. Use
`resources/cribrum/candidates/` or an explicit temporary candidate directory;
never place an experiment in `resources/cribrum/packs/` or `var/cribrum/packs/`.
Use `--candidate-packs=DIR --candidate-mode=shadow` on the offline CLI;
always supply `--response=FILE`
(or `--response=-`) so a context URL cannot trigger a target GET. Supply actual
captured DOM text only if available. `--capture-complete` declares an actually complete capture; it is not a way to
override truncation or supply missing family evaluations. A local SQL-only evaluation cannot assert
complete multi-family coverage or a pure case.

For a fully offline smoke run, create a synthetic local file and select this
example directory explicitly (the URL supplies context only):

```sh
php bin/console app:cribrum:classify \
  'https://fixture.invalid/search?q=demo' --response=/tmp/synthetic-response.html \
  --candidate-packs=/tmp/cribrum-candidates --candidate-mode=shadow --format=json --explain
```

Use a directory containing only pack manifests. Copy `contributor-triage.json` to a temporary
candidate directory before this run. Create the response file yourself from synthetic text; the
command never creates it or fetches the context URL.

A rule card should state:

- Stable identifiers and engine/pack/schema versions; observed family and limits.
- Required local error/query/input spans and named transformations.
- Positive examples, close counterexamples, and expected facts and absent claims.
- Incomplete/truncated/ambiguous-input behavior, and context still required.
- Baseline changes, collisions with existing rules, and activation/rollback plan.

Write expected results from the rule's semantics, before comparing classifier
output. Cover HTTP 200 diagnostics, ordinary documentation, escaped examples,
input reflected outside the error block, bound-parameter type/length failures,
duplicate parameter sources, encoding, Unicode offsets, and incomplete blocks.
The [public regression matrix](../../tests/Fixtures/cribrum/public-regression-v2.json)
is shared specification data; an expectation is not evidence of a passing test.
The [evidence example](../../tests/Fixtures/cribrum/evidence-signal-v1.json)
includes a non-ASCII prefix to exercise codepoint offsets.

For SQL relations, compare an original parameter after one normal URL-query
decoding; decode HTML entities only within the selected diagnostic block and
map proof spans back to raw bytes. Repeated decoding, unproved lowercasing,
page-wide co-occurrence, and short fragments do not establish a local structural
relation. A complete relevant block may be useful in an otherwise truncated
page; a truncated decisive block stays incomplete. A type error may show input
association without proving a query structure break. SQL injection remains a
suspected claim, not a successful exploitation claim. Include diagnostics do not
prove file reads or remote execution.

Run focused PHPUnit tests against synthetic inputs and the compiler. Compare
baseline and candidate on identical frozen bytes, recording added/removed facts
and claims, incorrect associations, unresolved context and coverage errors.
Keep a holdout family separate from tuning. Duplicate bodies/templates are not
independent positive examples. Hash equality proves repeatability, not a correct
label; an AI-generated label is not automatic ground truth. The selected corpus
does not justify a global precision/recall claim.

Public pull requests must contain no private URLs, real finding IDs, captured
response bodies, credentials, personal contacts, production cohort memberships,
or references needed only to resolve private paths. Build synthetic fixtures that
preserve syntax/encoding and the diagnostic relationship. Private optional
regression runs may be reported by version and aggregate limitations without
publishing their source data. Default behavior must not enroll any legacy member.

Validate the published schemas with a Draft 2020-12 implementation:

```sh
python docs/cribrum/validate-contracts.py
```

This script requires `jsonschema` (4.18 or later) in the selected Python
environment. It checks schema definitions, published examples, the complete
shipped candidate and invalid registry/activation attempts. It performs no target
requests or database writes. Runtime semantic tests remain required.
