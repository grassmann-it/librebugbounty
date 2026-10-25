# Cribrum contribution and handoff contracts

These public, versioned documents describe the in-repository rule library and its
finding/report adapters. Start with the [contributor guide](contributing.md), then
read the [evidence and snapshot contract](contracts-v2.md) and
[activation and rollback procedure](activation.md).
For the case UI, shared filters/sorting and report-gen coverage, read
[badges and case selection](badges.md).
For per-finding approval and case-specific rejection during image Review, see
[Review selection and rule feedback](review-selection.md).

Contributions currently arrive as reviewed GitHub pull requests. Registered
detectors are ordinary PHP project code; this delivery does not provide a
separately installable external plugin system or a standalone library package.
The existing project remains GPL-3.0-or-later; see [LICENSE](../../LICENSE).
No license for a future extracted package is assigned here.

The examples use invented `.invalid` hosts and synthetic content. Running the
local fixture workflow needs neither production data nor private evaluation
reports, a legacy cohort manifest, browser capture, Gmail, or target requests.
The library contract does not authorize production activation.

The [1.5.1 correction card](rule-cards/http-response-triage-1.5.1.md) describes
query/context false-positive fixes, successful-status diagnostic observations and
UTF-8 coverage corrections. The [1.5.0 card](rule-cards/http-response-triage-1.5.0.md)
retains the preceding query, frame, runtime, catalogue and WAF changes. Neither
card authorizes live detector activation.
