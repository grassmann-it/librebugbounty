# Native SQL suspicion hints / engine 1.3.5

This extension changes only core suspicion helpers. Candidate
`behavior-triage` **1.5.2**, its strict structural detector, explicit active mode
and report policy are unchanged. The preceding
[engine 1.3.4 card](mysql-syntax-suspicion-1.3.4.md) retains the original narrower
SQLSTATE `42000` / error `1064` grammar and its scoped verification record.

## Canonical MySQL/MariaDB messages and bounded tails

The core helper accepts the canonical native message beginning `You have an
error in your SQL syntax;`, with the MySQL/MariaDB manual/server-version wording
and one `near '…' at line N` location bounded to **2048 codepoints**. A SQLSTATE
prefix is no longer mandatory. If the carrier contains SQLSTATE, there must be
exactly one correctly formed `SQLSTATE[42000]: Syntax error or access violation:
1064` prefix bound to that message. A wrong state/code or malformed/repeated
SQLSTATE wrapper cannot be skipped to accept its inner message. Generic errors
and SQL-like page text remain insufficient.

Recognized diagnostic code prefixes (`ERROR NNN`, `mysqli_sql_exception(NNN)`,
`PDOException(NNN)`, `NNN -` and `Syntax error or access violation: NNN`) must
use `1064`. A recognized parenthesized state/code pair must use `(42000/1064)`.
This guard applies to those diagnostic forms, not every number in surrounding text.

The long exact request-value suffix requirements retain at least
**32 Unicode codepoints** and **90%** of the input, with at most **eight** omitted leading
punctuation/symbol codepoints, an ASCII quote in the original value, at least
eight Unicode letter/digit characters and eight distinct codepoints. Matching is
exact and case-sensitive. Request attribution
must identify one unique scalar or one leaf of a one-level URL-query array,
decoded once. Duplicate/conflicting roots, nested arrays and multiple matching
values do not qualify.

Comparison uses the raw near fragment or one local HTML-entity decoding of that
fragment. Only semicolon-terminated numeric/named entity tokens are decoded; URL
values are not decoded again and no recursive entity decoding occurs. Original
raw response spans remain authoritative, including encoded entity bytes. A
suffix cannot end inside an entity that expands to multiple codepoints; competing
bindings and decoded extra `near`/`at line` markers are rejected. The reason names
the local transformation when used.

A short tail permits **zero to eight** tabs, spaces or `(`, `)`, `,`, `;`, `%`
and ASCII single/double quotes, including short LIKE-wrapper punctuation. This
does not admit arbitrary trailing identifiers. A separate clipped-tail form
requires the entire comparison near fragment to be **exactly 80 UTF-8 bytes**,
excluding its outer native quotes. For the decoded comparison, original raw
entity text may be longer. This is a narrow retained-fragment shape, not a general vendor clipping
contract. Its tail permits **one or two spaces/tabs**, then **two to six ASCII
letters**, then exactly **three dots** and no further text.
The letters must be a case-insensitive prefix of `LIMIT`, `ORDER`, `GROUP`,
`WHERE`, `HAVING`, `AND`, `OR`, `UNION`, `JOIN` or `OFFSET`. For example, ` LI...`
qualifies; an arbitrary identifier, a missing/extra dot or trailing text does not.
No omitted keyword letters or remaining query are reconstructed. The reason
explicitly states that the near fragment ends in an ellipsis and the following
SQL text remains incomplete and unverified.

Invented input illustrating the retained-suffix boundary:

```text
Request scalar: ")probe_marker_alpha_0123456789_abcdefghijklmnopqrstuvwxyz_ABCDEFGHIJKLMNOPQ
Native error location: near 'probe_marker_alpha_0123456789_abcdefghijklmnopqrstuvwxyz_ABCDEFGHIJKLMNOPQ LI...' at line 1
```

This excerpt alone is insufficient: the qualified canonical native message,
original raw carrier, unique location and request attribution are still required.
It may occur with either native vendor wording and without a SQLSTATE wrapper.
The invented near fragment is 80 ASCII bytes; its retained suffix is 74 bytes and
the ` LI...` tail is six. These fixture identifiers contain no target data.

## PDO diagnostic with a disclosed query and ordered array inputs

A separate core fallback requires one qualified, complete raw syntax-diagnostic
carrier with the specific header `PDO Error: SQLSTATE[HY000]: General error:
20018 Incorrect syntax near '…'. [20018] (severity 15)`. The near token contains
1–64 nonquote/non-newline characters. The carrier must provide a bracketed
`[SELECT …]` query followed by `Query: SELECT …` and an HTML `<br>` boundary.
The two query copies must be byte-identical after trimming trailing ASCII
space/tab/CR/LF. Each copy is bounded to **4096 codepoints after the literal
`SELECT`**, rather than 4096 total. No database vendor is inferred from this
generic PDO diagnostic.

URL-query inputs are decoded once and limited to **64 pairs**. One eligible group
contains **2–12 distinct ordered leaves**, all named exactly `root[]`. Exactly one
leaf must be distinctive: at least 32 codepoints, an ASCII quote, eight letter/digit
characters and eight distinct codepoints, while not being an entire SQL-quoted
literal. Companion leaves must be optional-minus decimal integers with at most
ten digits and no leading zeros except zero. Scalar/index/nested conflicts and
another root containing the same long value are excluded.

The retained query must start with `SELECT `, have a neutral `FROM` and `WHERE`,
and contain one supported simple-column `IN (…)` expression with the exact leaf
values in request order. Whitespace around commas may vary; the long value is
case-sensitive and occurs exactly once. Open quote/bracket/backtick or comment
context before the relevant clauses cannot supply that relation. Outside the
masked long input, semicolons, ellipses and HTML tags are excluded. Multiple
bindings, mismatched query copies, unrelated sibling queries and request-supplied
whole diagnostic/query text do not qualify.

The hint retains three original raw spans: native diagnostic, disclosed query and
exact request value. It remains suspicion: execution and input-driven SQL
structure are unverified. A long XSS input can provide that distinctive value;
the helper does not infer XSS execution from the SQL error.

Invented local fixture inputs:

```text
Append leaves, in order: 4 ; ")probe_marker_alpha_0123456789_suffix
Disclosed query: SELECT sample_rows.id FROM sample_rows WHERE sample_rows.kind IN (4,")probe_marker_alpha_0123456789_suffix)
```

Both query copies must contain those exact bytes in the qualified PDO wrapper.
This illustrates a retained error query, not a successful SQL statement or an
instruction to contact a target.

## Certainty and unchanged boundaries

The output remains a core `sql_injection` **suspected** hint. The MySQL/MariaDB
suffix route retains explicit partial-input and missing-query uncertainty; a
complete disclosed query in the separate PDO route does not itself prove that
request input changed SQL structure. Neither route confirms SQL injection,
emits `sql_input_structure_indication`, creates active structural SQLi facts or
qualifies a report component. These fallbacks run after earlier core helpers
miss in core/candidate-shadow mode; explicit active mode bypasses them.

Raw-carrier guards still exclude request-provided whole diagnostics, documentation,
code/templates, JSON roots/catalogs and native-looking messages inside disclosed SQL data.
The generic `DB ERR` observation remains distinct from this native syntax hint.
Manual decisions, immutable prior results and candidate activation are unchanged.

## Validation and rollback

Focused local verification on **2026-10-26**:

- PHP 8.3.30 broader selection passed **880 tests / 54,639 assertions**; four
  unchanged costly classifier response-limit cases were excluded.
- A final frozen-source PHP 8.4.20 native/PDO suspicion, source-review service/UI
  and release-metadata selection passed **106 tests / 552 assertions** with
  engine 1.3.5 loaded.
- Synthetic regressions cover canonical MySQL/MariaDB formats, state/code
  conflicts, omission/retention bounds, local entity mapping, LIKE punctuation,
  clipped-tail grammar, PDO query copies and ordered-array attribution. Explicit
  active-mode output and report qualification remain unchanged. Four public
  schemas, four shipped examples and six registry/negative contract checks passed.
- Six scoped dry runs and applies created six new immutable engine 1.3.5 results
  for the identified SQL cases. Across nine inspected sources, original finding
  fields, manual claims, prior history and artifact/screenshot bytes stayed intact.
  No structural SQLi facts or SQLi report components were produced. Local case
  and diagnostics views showed the applicable suspected hints and current rules.
- Classification and review used retained local evidence without new target
  requests or candidate activation. The invented fixture examples above also
  yielded suspected hints with original raw evidence spans in offline checks.

This focused verification does not replace full release acceptance or establish
general precision/recall. Earlier engine/card counts remain historical.

For rollback retain **engine 1.3.4 plus candidate 1.5.2**. The unchanged candidate
manifest cannot revert this core helper by itself. Follow the
[activation and rollback procedure](../activation.md), preserving immutable
evidence/history and assessing current eligibility separately.
