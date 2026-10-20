# Roadmap

This tracks the 2.2.0 “Cribrum” candidate and future directions. Candidate work
is still subject to release acceptance; future items have no delivery date.
Scope and order will develop through use and feedback.

The current application uses SQLite, records completed contacts, and exports
reports for manual sharing. It has no integrated email delivery or AI provider.
See the [user guide](USAGE.md) for the current workflow.

## 2.2.0 — Cribrum candidate

Implemented in the candidate:

- Multiple claims per case, private bounded HTTP/DOM observations from existing
  browser runs, versioned local rulepacks and a synthetic regression corpus.
- Claim-aware Review, inventory/state filters, saved views, statistics and exports
  separating observation, suspicion and manual confirmation. Default reports
  exclude private HTTP artifacts; selected inclusion is explicit.
- Read-only observation diagnostics and pack/storage status, immutable local
  reclassification preserving manual decisions, backup listing and explicit
  retention previews, observation-aware evidence audits, upgrade permission
  preflight and honest installation-origin information.
- Additive migration and release/upgrade/backup acceptance from published 2.1.1.

C7–C9 and C4 acceptance are the current finalization scope. Configurable shortcuts
and measured performance/all-day-use work (C10) remain outside this implementation
pass. The candidate must not be treated as fully release-approved while required
acceptance or agreed release scope remains open. A private real corpus and backup
measurements on a representative private workspace are still needed.

Cribrum does not add automatic disclosure delivery or external AI calls. SQLi,
LFI and RFI heuristics remain suspicion; classification never replaces assessment.

## Configurable shortcuts

- Offer configurable shortcuts for useful actions across the workspace, with
  visible key hints and protection for text entry and normal browser controls.

Moneta already stores defaults for inventory page size, export profile, and
report screenshots alongside the intake marker, browser time limit, and review
decision pause. Explicit page selections override those defaults. Freely
configurable shortcuts are planned for 2.2.0; Review currently has its fixed key layout.

## Database portability

- Offer SQLite and a MySQL/MariaDB backend with consistent behavior for cases,
  assessments, and history.
- Keep SQLite useful for portable local workspaces, including complete database
  and artifact snapshots.
- Provide verified transfer between supported backends, preserving identifiers,
  relationships, manual decisions, history, and evidence references.

The server engine and supported versions still need to be selected and tested.
MySQL and MariaDB compatibility will be evaluated separately. A database change
will be guided by measured workload needs; it will not automatically speed up
PHP calculations or file access. The existing [backup tools](BACKUP.md) already
preserve a SQLite workspace and its artifacts together.

## Email and contact workflows

- Associate related cases with contacts and keep correspondence alongside the
  recorded contact history.
- Prepare editable email drafts and report attachments from selected cases.
- Explore mail-client or provider integration, with explicit user control over
  recipients, included data, and sending.

Provider support and the boundary between drafting, importing correspondence,
and sending remain open. Existing manual exports will remain a useful workflow.

## Optional AI assistance

- Help summarize selected cases and draft or translate report text.
- Suggest related-case groups and contact associations with understandable
  reasons and links to the supporting records.
- Keep suggestions reviewable, with stored evidence and manual assessments
  remaining authoritative.

Integration should be optional and make clear which selected data is shared
with a model provider. Provider choices and local-processing options remain
open.
