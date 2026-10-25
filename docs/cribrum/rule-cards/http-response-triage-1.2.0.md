# HTTP-response triage: candidate 1.2.0

This is a compatible extension of the `behavior-triage` candidate pack. It keeps
pack schema 2, observation-evidence schema 1 and the existing detector/output
identifiers. It changes recognition, not report policy or model selection.
Candidate selection remains explicit and shadow is the default; see
[activation and rollback](../activation.md).

## SQL diagnostic relationships

Detector: `sql-triage-v1`. Outputs remain `sql_error_observed`,
`input_in_sql_error` and `sql_input_structure_indication`.

- Recognize German JET/Access `Syntaxfehler in Abfrageausdruck` alongside the
  existing English query-expression diagnostics. German SQL Server near-syntax
  diagnostics remain context-required when the complete input cannot be mapped;
  MySQL `Incorrect DATETIME value` is a type diagnostic, not a structure break.
- Handle a leading input quote after an application's SQL `LIKE` wildcard
  prefix only when the complete locally mapped input breaks the string operand.
- Recognize the adjacent Message and SQL rows of a bounded Lucee database-error
  table. The complete input must occur in its query, corroborated by the local
  near diagnostic. The near excerpt may begin immediately after the leading input
  quote that breaks the literal; this does not reconstruct a missing query value.
  Unrelated cells, separate error objects and page-wide echoes do not qualify.
- Preserve the distinction between SQL operators and operator-like text inside
  string literals, quoted identifiers and comments. An innocent parameter
  appearing there must not establish a structural relationship. Unsupported
  escape/dialect contexts remain unresolved.

Input comparison uses one URL-query decode and one HTML-entity decode in the
selected diagnostic object, with proof spans mapped back to the original UTF-8
response. Duplicate parameter sources, incomplete decisive blocks, partial input
fragments and unproved transformations remain unresolved. Type, length, collation
and bound-parameter failures do not establish an SQL structure break. A qualified
structure indication supports suspicion only; it proves neither exploitation nor
data extraction. Reflection does not prove XSS execution.

Synthetic regression cases are in
[`SqlTriageDetectorTest.php`](../../../tests/Cribrum/SqlTriageDetectorTest.php).
They include positive diagnostic relationships and nearby negative variants,
including separate objects, escaped input and quoted operator text.

## Failed file inclusion

Detector: `file-triage-v1`. Recognize the target in PHP's
`require(): Failed opening required '…'` fatal form as well as the existing
warning form. A concrete diagnostic can occur under HTTP 200; no global status
gate is lowered. Fixed include targets and source-location paths remain
diagnostics without an inferred input relationship.

The existing `lfi_suspected` / `rfi_suspected` meanings require their existing
input-to-target relationship. A failed include does not prove successful file
access, arbitrary path selection or remote execution. Fixtures and counterexamples
are in [`file-triage`](../../../tests/Fixtures/cribrum/file-triage/).

## Disclosure and block context

Detector: `context-triage-v1`.

- Recognize concrete internal paths under the `/homepages/` hosting root in
  diagnostic/stack content and under `/customers/` in PHP frames.
- Recognize a bounded embedded PHP error object with `type`, `message`, `file`
  and `line` together, including WordPress fatal JSON tails without stackframes.
  It requires a complete message string, an internal absolute PHP path, a
  positive line number and a closed object. Unrelated JSON fields do not qualify.
- Add bounded Check Point block, Azure Application Gateway WAF challenge and
  SecuPress rejection variants and an additional explicit NinjaFirewall rejection
  template using the existing `coverage_limited` meaning.
  Product names or passive assets alone remain product context.

An actual diagnostic with internal paths or frames can support
`internal_information_exposed`. A framework name or generic error shell alone
does not. Block/challenge evidence does not suppress independent application
evidence from the same response. Tests are in
[`ContextTriageDetectorTest.php`](../../../tests/Cribrum/ContextTriageDetectorTest.php).

## Evaluation and limits

Synthetic expectations are part of the public tests; private captured responses
and their identifiers are not required to contribute. Compare the previous
candidate and this version on identical immutable bytes, retaining original
capture time separately from interpretation time. Record both new detections and
removed unsupported associations. A changed rule result on an old capture is
not evidence that the website has since changed.

These variants do not provide a general SQL parser, arbitrary framework-object
extractor or complete signature catalog. Unknown transformations and selector
contracts still need review. Compressed wire bytes require a separately defined,
bounded representation conversion; a gzip stream is not a textual rule miss.
Selected regression cases do not establish global precision or recall.

Rollback uses the previously reviewed engine **and** pack version together;
changing only the manifest version does not restore old detector behavior.
Historical evidence and manual review decisions must be retained.
