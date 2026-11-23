# HTTP response triage 1.5.2 / engine 1.3.3

This narrow correction extends the preceding
[candidate 1.5.1 / engine 1.3.2](http-response-triage-1.5.1.md) SQL diagnostic
handling. Candidate selection remains explicit and shadow by default. Installing
source neither activates a candidate nor replays stored captures automatically.
Existing manual assessments, immutable history and report policy remain separate
from classification.

## Generic database diagnostic with a concrete query

A standalone visible `DB ERR` diagnostic immediately followed by a concrete
relational SQL statement can supply `sql_error_observed` without a database-vendor
signature.
Supported statement forms are `SELECT`, `UPDATE`, `INSERT` and `DELETE`; a
multiline `SELECT COUNT(...) ... FROM ...` is one example. The statement must
qualify within the same bounded text region; separate HTML containers are not
joined to manufacture it.
This includes successful HTTP responses and a diagnostic at the end of the body
whose HTML container has no closing tag. The missing closing tag does not turn
the diagnostic into a completed capture or certify full evaluation coverage.

Synthetic example, with invented identifiers and no target request:

```html
<div class="content__wrapper">DB ERR SELECT COUNT(sample_rows.id) AS total
FROM sample_rows
WHERE sample_rows.label = 'example'
```

The generic diagnostic retains an unknown vendor and observed-error certainty.
`DB ERR` alone, without a qualified query, is insufficient. The wrapper does not
establish an SQL parser failure caused by request input, structural SQL-injection
suspicion, or confirmed SQL injection. A reflected value inside the returned query
does not change that boundary. A complete closed diagnostic may still map
`input_in_sql_error` as an observation. An incomplete diagnostic context cannot
establish that input relationship. Query disclosure and input mapping retain their
independent source and context checks.

Request-provided diagnostics reflected unchanged and documentation/code contexts
remain excluded. Exact source spans continue to bind qualified observations to
the retained response. Core observed-diagnostic suggestions do not publish active
candidate facts or claim complete detector coverage; candidate shadow results
remain evaluation output.

## Validation and rollback

Synthetic regressions cover the bounded HTTP 200 positive, an unclosed diagnostic
container, missing or incomplete query context, request-provided echoes,
documentation/code negatives, nested error-looking query values and explicit
context budgets. On 2026-10-26 the PHP 8.3.30 SQL/classifier/candidate/reclassification
selection passed **585 tests / 52,603 assertions**. Public schema/example and
registry/negative contract checks also passed. A broader PHP 8.4.20
Cribrum/projection/interactive-capture selection reported **1,457 tests / 58,500
assertions**, no failures and **40 skipped optional acceptance cases** with
report-gen/private fixtures unavailable. Offline replay of the affected
retained input preserves XSS and adds observed error disclosure in core and shadow
modes. No private response bodies or original target identifiers are required for
these tests. Offline evaluation of retained bytes is not a fresh target test.

This focused verification does not replace full release acceptance. Dated counts
for earlier source pairs remain historical.

For rollback retain **engine 1.3.2 plus candidate 1.5.1** together. Changing only
the candidate manifest does not restore the preceding PHP detector behavior.
Follow the [activation and rollback procedure](../activation.md), preserving
immutable evidence and history while re-evaluating current eligibility separately.
