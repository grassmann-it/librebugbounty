# HTTP response triage 1.5.1 / engine 1.3.2

This corrective revision keeps candidate selection explicit and shadow by default.
It changes no database schema, manual assessment, existing classification history,
report selection or detector activation setting. Existing captures are not replayed
automatically when the code is installed.

## Corrected distinctions

- Concrete SQL/runtime diagnostics can yield observed core claims even at HTTP 200.
  A generic error title, product/CDN name or a complete diagnostic supplied by the
  request is insufficient. A database error does not by itself establish SQLi.
- Query disclosure uses bounded statement and carrier context rather than joining
  unrelated English `SELECT`/`FROM` words across HTML. Server-generated SQL in a
  hidden form field can be evidence; supplied SQL reflected unchanged, attribute
  lookalikes, scripts, templates and documentation are excluded.
- HTML-formatted PHP notices and warnings retain exact source spans, including
  notices carrying concrete internal paths. Runtime diagnostics and independently
  exposed internal details remain distinct assertions.
- Template/include messages inside translation catalogs are data. Structured error
  responses bind evidence to their actual error field, not neighboring catalog
  values. Include-error evidence does not automatically establish LFI/RFI.
- The bounded SQL JSON scan starts at a UTF-8 boundary. Omitted input still records
  a budget limitation; fixing a character boundary does not certify full coverage.

Core diagnostic suggestions reuse qualified observations without publishing active
candidate facts or claiming full detector coverage. Candidate shadow evaluation
retains the same core suggestions. Policy and manual review still determine which
evidence can be included in a report.

## Regressions

The PHP suites include synthetic positive and negative fixtures for response prose,
request-provided queries, hidden fields and their HTML contexts, successful-status
diagnostics, JSON catalogs and structured errors, exact Unicode spans, and bounded
multibyte JSON scans. No private response bodies or original target identifiers are
required to run these tests. Private retained captures can additionally be replayed
offline to compare before/after results; that replay is not a fresh target test.

For rollback retain the engine **1.3.1** and candidate **1.5.0** source pair. A
pack-only version change does not restore old PHP detector behavior. Follow the
[activation and rollback procedure](../activation.md); retain immutable history and
re-evaluate current eligibility separately from historical results.
