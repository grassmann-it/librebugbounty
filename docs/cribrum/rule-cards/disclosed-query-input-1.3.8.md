# Disclosed-statement input interpolation — engine 1.3.8

This correction adds one core suspicion helper and one response carrier. It
changes only core code. Candidate `behavior-triage` **1.5.2**, its strict
structural detector, explicit active mode and report policy are unchanged. The
preceding [engine 1.3.7 card](native-carriers-and-runtime-1.3.7.md) retains the
native serialized-alert, localized Access and database-result runtime families.

## Request values carried in the URL path

A payload is frequently placed in the path rather than the query string. The
core request-value handling previously read only the query string plus an
explicit payload field, so a path-carried payload left the SQL paths without any
request value at all. Decoded path values now exist as request values:
percent escapes are decoded once, as RFC 3986 path semantics require, and both
the complete decoded path and each segment are retained, because a payload can
itself contain path separators. Values are bounded to 64 segments and 512
codepoints.

Path values enter only the **source-bound** carriers, and only when they carry
SQL quoting characters (ASCII single/double quote, backtick or backslash). They
deliberately stay out of the core context needles: a reflected path alone must
not qualify a page-wide error line as SQL-injection suspicion. A path value
without quoting characters cannot distinguish unescaped interpolation from a
bound parameter or a safely quoted literal.

## The multi-line response carrier

A rendered debug page can echo one complete statement across several lines. The
existing carriers read a single response line, or a paragraph whose opening tag
had no attributes, so a statement inside `<p class="…">` was not recognized at
all. The new bounded `response_block` carrier reads one statement until a blank
line, a closing block tag (`</p>`, `</div>`, `</pre>`, `</li>`, `</td>`, `</tr>`,
`</h1>`–`</h6>`, `</body>`, `<br`) or the response end. No following dump is read
into it. It keeps every guard the line candidate applies: an active HTML
carrier, a complete relational statement, markup only inside SQL literals, a
structural clause, and the request-supplied-query guard.

The carrier feeds the existing `builtin/sql-query-echo` query disclosure, which
remains an information disclosure and is explicitly not SQL injection.

## `builtin/sql-disclosed-query-input`

The helper observes one disclosed statement that contains one request value
**verbatim**, where that value carries SQL quoting characters and directly abuts
the statement's own quoting. The application interpolated the value into the
statement without escaping it: an escaping application would have transformed
those characters, so the verbatim bytes are themselves the observation.

Guards, all of which must hold:

- Exactly one statement and one value bind. Two competing bindings stay
  unqualified rather than picking a winner.
- The statement text must be verbatim in the response. An entity-decoded
  attribute value cannot supply exact raw evidence.
- An escaped occurrence is rejected: an odd run of backslashes directly before
  the value escapes it. A value inside a line, hash or block comment is
  rejected.
- The value must abut SQL quoting. An identifier, prose or operator position
  does not qualify.
- A request value that contains the whole statement supplied it; the response
  then echoes the requester, not the application.
- Inert and scripted carriers (`code`, `samp`, `script`, documentation
  class/role) are excluded through the shared active-text guard. A
  preformatted container remains a valid carrier: application dumps and
  documentation share it.

The output is a core `sql_injection` **suspected** hint at score 86 with two raw
spans: the disclosed statement and the exact request value. It emits no typed
evidence, no `sql_input_structure_indication`, no structural fact and no SQLi
report component. The statement is application output; neither its execution nor
a changed SQL structure is verified. Explicit active mode bypasses the helper.

Invented local fixture input:

```text
Request path: /'"><img src=0 onerror=alert('fixtureMARK')>
Disclosed statement: SELECT V.* FROM fixture_valores V
 LEFT JOIN fixture_seo S ON V.seo_id = S.id
 WHERE 1 AND S.url_es = ''"><img src=0 onerror=alert('fixtureMARK')>'
```

This excerpt alone is insufficient: the bounded carrier, the verbatim raw bytes,
the single binding and the request attribution are still required. The fixture
identifier contains no target data.

## Known boundaries

- HTML-entity-encoded reflections defeat verbatim matching, including the
  existing hidden-`squery` field carrier. The raw response is authoritative.
- A single-line carrier still stops at reflected markup, which is why the
  multi-line block carrier exists.
- A request value without quoting characters is not evaluated. This trades
  recall for precision and is a deliberate boundary, not an oversight.
- A preformatted documentation block that repeats a request value verbatim
  inside a statement remains a false-positive vector.

## Validation and rollback

Focused local verification on **2026-11-09**:

- New family selection `tests/Cribrum/SqlDisclosedQueryInputSuspicionTest.php`:
  **23 tests / 53 assertions** on PHP 8.4.27, passed. It covers path, query,
  final-URL, payload and array-leaf carriers; single-line and multi-line
  carriers; unqualified values, escaped and commented occurrences, non-abutting
  positions, entity-encoded carriers, supplied statements, inert carriers,
  competing bindings, statement budgets, the active-mode bypass and the engine
  issue surface.
- Full local suite on PHP 8.4.27 with SQLite: **3,486 tests / 90,418
  assertions**, one unrelated ordering failure in
  `SourceReviewServiceTest::testMigrationMatchesOrmAndCompletedRowsRejectDirectUpdates`
  (passes in isolation; it asserts a schema diff and is sensitive to an earlier
  test's table state, not to this change).
- Offline replay of the inspected retained context reproduced the reported miss
  exactly: only a reflection claim before, and query disclosure plus SQLi
  suspicion after. One scoped dry run and application created the new immutable
  result while preserving the earlier XSS claim revision and all artifact bytes.

This focused verification does not replace full release acceptance or establish
general SQL detection accuracy. Earlier engine/card counts remain historical.

For rollback retain **engine 1.3.7 plus candidate 1.5.2**. The unchanged
candidate manifest cannot revert this core helper by itself. Follow the
[activation and rollback procedure](../activation.md), preserving immutable
evidence/history and assessing current eligibility separately.
