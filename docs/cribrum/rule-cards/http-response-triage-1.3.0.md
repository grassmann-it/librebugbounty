# HTTP-response triage: candidate 1.3.0

This compatible extension of [candidate 1.2.0](http-response-triage-1.2.0.md)
keeps pack schema 2, evidence schema 1, detector/output IDs and report policy.
It is selected explicitly; shadow remains the default. Restore **engine and pack
together** when rolling back. See [activation](../activation.md).

## SQL: local operand relationships

`sql-triage-v1` retains a diagnostic when a damaged reflected link contains an
ordinary query parameter after the damaged input. A nearby `=` is insufficient
to make that parameter an SQL operand. A structural indication requires a
complete supported left operand at a neutral expression/clause boundary, plus
the existing exact input and lexical checks. This does not ban URL or ampersand
inputs: a complete input used as a real SQL operand remains eligible. Complex
unsupported left expressions remain diagnostic context.

A bounded Yii diagnostic can supply `The SQL statement executed was: SELECT …`
in the same error object. Full, once-decoded input at a qualifying operand
supports `sql_input_structure_indication` with `assessment=suspected_only`.
Separate objects, partial/transformed values, placeholders, duplicate sources
and incomplete decisive blocks cannot establish the relation. Generic `ERROR`,
Yii or SQLSTATE text does not identify a database vendor; unsupported vendor
identity is `unknown`. German SQL Server varchar-to-int conversion remains a
nonstructural diagnostic even with complete locally associated input.
Vendor recognition uses local provider/diagnostic evidence, excluding executed
SQL and quoted input fragments. A table named `access` or an input containing a
database name does not identify the server.

Public tests include the four link/operand controls, renamed parameters, Unicode,
quoted identifiers, real full URL operands, Yii object boundaries and vendor/type
counterexamples. See [SQL tests](../../../tests/Cribrum/SqlTriageDetectorTest.php).

## Prefixless include diagnostics

`file-triage-v1` recognizes bounded title/H2 `Failed opening required 'target'
(include_path='…')` diagnostics with raw or entity quotes, including HTTP 200.
The diagnostic and exact target retain original response spans. Missing operation
names are omitted: the word `required` does not establish `require()` or
`require_once()`. This new route is diagnostic-only, including input coincidence;
existing qualified operation/target routes keep their own LFI/RFI prerequisites.
Fixed targets, search paths, remote-wrapper failures and redirects cannot become
successful file access or execution. Local code/sample containers, broken quotes
and cross-element targets are counterexamples. See
[file fixtures](../../../tests/Fixtures/cribrum/file-triage/cases.json).

## Concrete internals

`context-triage-v1` recognizes bounded Symfony source frames, chains of actual
Zope/Python module frames (including delivered hidden/comment content), matched
absolute template start/end comments, and concrete absolute paths in the new
include diagnostic. It emits existing `internal_information_exposed` with
`detailKind=stack_trace` or `internal_path`; a fixed path is not LFI.

CSS names, traceback placeholders, unpaired/mismatched comments, relative log
pointers, public configuration, passive assets and code examples do not establish
these facts. Separate real application evidence on the page remains eligible.
See [context tests](../../../tests/Cribrum/ContextTriageDetectorTest.php).

## WAF context

Active go-away browser verification and SafeLine v2 challenge loading/calling
produce `coverage_limited` with `reason=waf_challenge`. Product presence remains
`product_context`. Unselected translated deny text is not a current block.
Explicit Admin Tools request rejection or current suspension can establish
`reason=waf_block`; conditional recovery advice alone cannot.
Textareas, noscript/template content and non-executable script types cannot
supply these new current-action facts. These exclusions are local to WAF evidence;
delivered hidden/comment frames can still expose real application internals.

A challenge does not fabricate `relevantRequestActuallyBlocked`. Under the
unchanged policy it cannot alone cause the E7 review/mail hold. A new relevant
explicit block with an independent XSS basis does require review; XSS remains
confirmed. Repeating an acknowledged identical block does not create a new hold.

## Consumer acceptance and limits

[Engine acceptance tests](../../../tests/BehaviorEnginePipelineAcceptanceTest.php)
use the real registered classifier, stored observation, projection and filters,
and the optional local report-gen test consumer. They require:

- Corrected SQL association: diagnosis retained, no SQLi component/urgency, still
  excluded from `pure_xss`.
- Qualified Yii relation: one mixed case with confirmed XSS and suspected SQLi.
- New concrete internals: disclosure subject to the existing gate and pure filters.
- Challenge without block proof: no E7 hold; explicit new block: hold until review.
- Unchanged PoC bytes and recipient leak guard in local report composition.

Compare Core, frozen 1.2.0 and 1.3.0 on identical immutable bytes. Record removed
associations and vendor changes as well as new detections. Public fixtures are
synthetic; private captures and identifiers stay outside the repository. Reviewed
captures are regressions, not an untouched holdout; duplicate bodies are not
independent cases. Selected tests do not establish global precision or recall.

This tranche adds no migration, new mail type, vendor-specific UI or model routing.
DateTime/GraphQL/Solr contexts, gzip decoding and semantic response completeness
remain separate work. Rule changes on old captures are not website state changes.
