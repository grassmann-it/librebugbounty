# Native carriers and database-result runtime errors — engine 1.3.7

This correction handles separate evidence levels. A native database syntax
diagnostic with a qualified request relationship can support SQL-injection
**suspicion**. A PHP result/resource type failure supports an observed runtime
error; it supplies neither a native SQL syntax diagnosis nor input causality.

## Native syntax carriers

Two core helpers run in default and candidate-shadow modes:

- `builtin/sql-serialized-native-diagnostic` reads a complete canonical
  MySQL/MariaDB syntax diagnostic inside an isolated JavaScript `alert(string)`
  source frame, optionally followed by `history.go(-1)`. It does not accept
  arbitrary script programs, catalogs, documentation or supplied diagnostics.
  One explicit JavaScript-string decoding pass maps evidence back to raw tokens;
  HTML entities are not expanded inside script strings. The source may contain
  literal closing script markup without establishing valid JavaScript execution.
  SQLi suspicion additionally requires one unique scalar value, a distinctive
  exact long suffix retaining at least 90% with at most eight leading punctuation
  codepoints omitted, and bounded native suffix/clipping grammar. Missing SQL
  text is never reconstructed.
- `builtin/access-expression-diagnostic` recognizes a bounded native Microsoft
  Access `80040e14` expression diagnostic with its adjacent ASP source location.
  Supported Italian and English messages yield an observed error independently
  of input binding. `builtin/access-expression-input` additionally requires one
  unique, complete, distinctive scalar request value at an unquoted comparison
  operand in a supported simple comparison/AND/OR expression. A locally named
  one-pass entity comparison retains original raw spans. A complete SQL
  statement and database execution remain unproved.

Both families reject request/redirect-supplied diagnostics and ambiguous input
binding. Neither emits typed SQL evidence or changes the strict explicit active
SQL detector, structural facts or SQLi report qualification.

## Native PHP runtime carriers

The runtime family reads native supported PHP fatal/uncaught diagnostics with an
absolute PHP source location without requiring a closed outer HTML container.
The colon-and-stack form requires a local `#0` frame and matching final thrown
file/line. Trace length, frame count and source path lengths are bounded. Native
MySQL/mysqli result/resource warnings have their own bounded function/type and
source-location grammar. Raw/DOM source coordinates stay distinct; documentation,
scripts and wholly supplied diagnostic echoes are excluded.

The result/resource family uses `builtin/database-result-runtime` for an observed
error claim, with an explicit explanation that `false` in place of a result or
resource does not by itself establish SQL injection. Existing internal-path and
stack disclosure observations remain separate. In explicit candidate active
mode, the runtime correction can add an `error_response` observation; it cannot
add a SQL structural fact or SQLi report component.

## Native query disclosure and visible causes

A bounded PostgreSQL `SQLSTATE[2201X]` negative-OFFSET diagnostic may include a
complete relational SELECT after `query was:`. The first explicit HTML break
closes this carrier; later trace and request/print_r dumps are not statement
text. Supported LIMIT/OFFSET orderings, neutral lexical state and balanced
parentheses are checked within the existing bounds. This path adds query
disclosure, including `internal_information_exposed` in explicit active mode;
it neither binds a reflected request value nor supplies SQLi evidence. The
independent large-trace limit remains visible.

The UI can show **SQL-Fehler** or **Datenbank-Laufzeitfehler** from a whitelisted
cause on the exact immutable error-claim revision. Current causes require the
matching source, PoC, request pin and artifact in a current report projection;
unqualified or stale causes appear in history. These labels remain observed,
even beside a manually confirmed parent error claim. Summary, filter and export
share the same qualification, without adding report components or facts.

## Historical source binding

An older stored result may omit a diagnostic that the current engine already
recognizes offline. Missing capture-time request hashes remain unknown even when
the URL still matches. The original capture owner must have retained the pin;
replaying must not reconstruct it from today's request fields. Such a result may
remain inspectable as history without changing current claims. A newly pinned
capture or separate manual decision is required for a current interpretation.

## Validation and limits

Synthetic helper and runtime fixtures cover positive/negative carriers, input
ambiguity, one-pass transformations, exact raw spans and explicit default,
shadow and active policy outcomes. The policy matrix distinguishes missing input
binding from a missing diagnostic and requires absent SQL structural/report
evidence. Retained-source replays and scoped application are recorded separately
in release preparation notes. These are family corrections, not independent
holdout measurements or a claim of complete SQL detection.
