# PHQL query-input suspicion / engine 1.3.6

`builtin/phql-order-input` is a core-only `sql_injection` **suspected** hint.
Phalcon PHQL is an ORM query language. Its parser error does not establish a
native database error, database vendor, query execution or confirmed injection.
Candidate `behavior-triage` **1.5.2**, typed SQL facts, explicit active mode and
report qualification are unchanged.

## Qualified source and input relationship

The raw response must contain exactly one `Syntax error, unexpected token …,
near to '…', when parsing: SELECT … (N)` diagnostic. The query is bounded by
the declared byte length and an adjacent `<br><pre>` stack trace whose first
frame is `Phalcon\Mvc\Model\Query\Lang::parsePHQL()`. The trace must close.
The query is limited to 4096 codepoints after `SELECT`, the near excerpt to
2048, and the trace remainder to 8192. The declared length is checked against
the original query's UTF-8 bytes, rather than its character count.

One distinctive request value must occur once as a whole unquoted `ORDER BY`
operand, either first or after supported identifier terms. The prefix is checked
in PHQL context: bracketed model namespaces and typed array placeholders are
allowed, while open literals, comments, extra statements and unbalanced
parentheses cannot supply a neutral operand position. Supported surrounding
sort terms use simple identifiers, optional `ASC`/`DESC`, and an optional numeric
or named-placeholder `LIMIT`. The request value's own malformed syntax is not
interpreted as trusted surrounding query syntax.

URL-query values are decoded once, retaining every occurrence. The selected
scalar or one-level array leaf must have one unique normalized root; duplicate,
scalar/array and nested selected-root sources are ineligible. Unrelated nested
or repeated parameters do not discard a uniquely attributable scalar. Another
parameter containing the same value prevents attribution. Inputs are limited
to 128 pairs; the selected value needs at least 32 Unicode codepoints, at most
1024 bytes, an ASCII quote or angle bracket, eight letters/digits and eight
distinct codepoints. An entire quoted SQL string literal is not eligible.

HTML tags within the query are preserved as literal bytes. A query with no raw
`<` may additionally be compared after one HTML-entity decoding, with complete
token endpoints mapped back to the raw representation. No tag deletion, repeated
decoding, case-folding of input values or reconstructed query text supplies the
binding. The emitted spans retain the original diagnostic, query, request-value
rendering and parser frame.

Documentation, code/script/template and inactive HTML contexts, request-supplied
whole diagnostics or queries, multiple carriers, wrong lengths, partial queries,
literal/comment positions and generic parser errors are excluded. A PHQL error
alone, or page-wide co-occurrence of a query and input, does not suffice.

Synthetic shape, using invented identifiers:

```text
SELECT [Fixture\Models\Item].* FROM [Fixture\Models\Item]
WHERE id IN ({ids:array}) ORDER BY rank ASC, <full distinctive request value>
LIMIT :APL0:
```

This is an error-query representation, not a valid executed statement. The
qualified diagnostic, exact byte count, closed parser trace and unique request
binding are still required.

## Policy and verification

The helper runs only in core/default and candidate-shadow modes after earlier
core suspicion paths miss. Explicit active mode bypasses it. It emits no
`sql_error_observed` or `sql_input_structure_indication` fact and cannot create
an eligible SQLi report component. Existing independently qualified claims stay
separate. The reason explicitly states the PHQL/ORM and execution uncertainty.

Family regressions and the independently specified per-mode policy matrix are
in [SqlPhqlInputSuspicionTest](../../../tests/Cribrum/SqlPhqlInputSuspicionTest.php)
and [SqlSuspicionPolicyMatrixTest](../../../tests/Cribrum/SqlSuspicionPolicyMatrixTest.php).
Actual scoped verification is recorded in the
[release preparation notes](../../../.github/releases/v2.2.0.md). These checks
do not demonstrate unseen-family recall; see the
[retrospective](../retrospective-2026-10-27.md).

Final frozen-source verification on **2026-10-27** passed **244 tests / 2,006
assertions** on each of PHP 8.3.30 and PHP 8.4.20. Ten retained sources were
evaluated in three modes; the prior-source claims and active facts remained
unchanged. One scoped offline reclassification added an immutable suspected
result with matching raw spans, preserved original evidence/history and no SQLi
report component. Its actually read source has a current hash/context-bound
review receipt. No target request or candidate activation was used.

Rollback requires the preceding **engine 1.3.5** implementation; replacing the
unchanged candidate manifest alone cannot revert a core helper. Preserve prior
immutable observations and follow the [rollback procedure](../activation.md).
