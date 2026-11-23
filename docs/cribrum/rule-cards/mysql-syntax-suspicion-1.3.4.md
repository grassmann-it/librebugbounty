# Native MySQL syntax suspicion / engine 1.3.4

This correction changes only the core suggestion helper. Candidate
`behavior-triage` **1.5.2** is unchanged, including its strict structural detector
and explicit activation settings. The preceding
[engine 1.3.3 / candidate 1.5.2 card](http-response-triage-1.5.2.md) retains the
generic `DB ERR` observed-diagnostic correction and its scoped validation record.

## Narrow partial-input hint

A qualified native MySQL diagnostic containing both SQLSTATE `42000` and error
`1064`, with the native syntax-error/manual-version wording, can support a core
`sql_injection` **suspected** suggestion in its raw response carrier. Its single
`near '…' at line N` excerpt is bounded to **2048 codepoints** and must begin with
a long exact, case-sensitive suffix of an unambiguous request value. Only one qualified carrier
and location can supply the hint.

The excerpt must retain at least **32 Unicode codepoints** and **90%** of that
value. At most **four leading punctuation/symbol codepoints** may be omitted;
omitted letters, digits or whitespace are disallowed. The value must contain an
ASCII single or double quote, at least eight Unicode letter/digit characters and
at least eight distinct codepoints; repetitive padding alone is insufficient. After the suffix,
at most **eight** spaces, tabs or `(`, `)`, `,`, `;` characters may follow. An
identifier, keyword or another error-location clause is not an allowed tail.

The request source must identify one unique URL-query scalar or a single leaf of
a one-level array parameter. Values are URL-decoded once. Duplicate/conflicting
root keys, nested arrays and the same matching value in multiple parameters do
not provide unique attribution. A short common fragment does not qualify.

Invented input for a local fixture:

```text
Request scalar: ")probe_marker_alpha_0123456789_suffix
Diagnostic excerpt: near 'probe_marker_alpha_0123456789_suffix' at line 1
```

The excerpt alone is insufficient. It must belong to the qualified native MySQL
syntax diagnostic, with the original response carrier, bounds and source checks.
The two omitted leading characters in this invented example remain missing;
the helper does not reconstruct them or an executed SQL statement.

## Certainty and unchanged boundaries

This is a triage hint with explicit **partial-input and missing-query
uncertainty**. It does not establish a complete input-to-query mapping or prove
that the supplied input changed SQL structure. It never confirms SQL injection,
adds `sql_input_structure_indication`, creates an active structural SQLi fact or
qualifies a report component. The strict candidate structural detector, explicit
active-mode behavior, report policy and manual decisions remain unchanged.
Core/candidate-shadow suggestions and qualified active evidence remain distinct.
The helper runs as a fallback when the existing full-request-value helper finds
no suggestion in core or candidate-shadow mode. Explicit active mode bypasses it.

Request-supplied diagnostic echoes, documentation/code examples, generic database
wrappers, missing native syntax context, short suffixes, excessive omissions,
omitted letters/digits/whitespace and ambiguous request sources must not acquire this hint.
In particular, the preceding generic `DB ERR` observation does not become SQLi
suspicion through this helper.

## Validation and rollback

Focused local verification on **2026-10-26**:

- PHP 8.4.20 SQL/core/classifier selection passed **551 tests / 52,165
  assertions**; four unchanged costly classifier response-limit cases were
  excluded. PHP 8.3.30 broader core/candidate, persistence, reclassification,
  claim/filter, badge, report-policy/projection, interactive-capture and
  release-metadata selection passed **555 tests / 3,237 assertions**.
- Four public schemas, four shipped examples and six registry/negative contract
  checks passed. Synthetic regressions cover scalar/array-leaf attribution, long
  exact suffixes, prefix/tail bounds, duplicate/conflicting parameters, raw
  carrier/native-diagnostic guards and unchanged active/structural behavior.
- Exact retained-input replay verified raw evidence spans and core/shadow
  suggestion parity. Active-mode output remained unchanged without structural
  SQLi facts.
- A scoped local dry run/apply processed **one observation / three suggestions /
  zero skipped**. Existing finding fields, original observation, prior history
  and artifact/screenshot hashes remained unchanged; one immutable engine 1.3.4
  result was added. Error/XSS stayed observed and SQL injection stayed suspected;
  no SQLi report component was produced.
- Local case and diagnostics views returned HTTP 200 and displayed the suspected
  SQL-injection badge, current rule and explicit uncertainty. No new target
  request or candidate activation was used.

This focused verification does not replace full release acceptance. Earlier
engine/card test counts remain historical. The synthetic fixtures require no
private response bodies, original target identifiers, live capture or target
request; replay of retained bytes does not measure general precision or recall.

For rollback retain **engine 1.3.3 plus candidate 1.5.2**. The candidate manifest
did not change, so a pack-only version change cannot revert this core helper.
Follow the [activation and rollback procedure](../activation.md), preserving
immutable evidence/history and assessing current report eligibility separately.
