# Roadmap

These are future directions beyond LibreBugBounty 2.1.1 “Moneta (Conatus)”. They are not
features available in the current release or commitments to a delivery date.
Scope and order will develop through use and feedback.

The current application uses SQLite, records completed contacts, and exports
reports for manual sharing. It has no integrated email delivery or AI provider.
See the [user guide](USAGE.md) for the current workflow.

## Planned 2.2.0 — Cribrum

Cribrum is under development. Its planned scope combines:

- Multiple claims per case, bounded HTTP/DOM evidence from existing browser
  runs, and a local, versioned classifier with reviewable suggestions.
- Claim-aware review, inventory filters, statistics, and report exports, with
  compatible upgrades and backups for existing cases and evidence.
- Backup listing and explicit retention tools, a read-only evidence/storage
  audit, upgrade preflight diagnostics, clearer release-version information,
  and targeted dependency/security fixes where needed.
- Configurable shortcuts, measured inventory/statistics performance work, and
  corrections supported by findings from everyday use.

The maintenance and workflow items previously considered for separate 2.1.x
patches are now part of the 2.2.0 plan. Performance changes depend on measured
benefit. Cribrum does not add automatic disclosure delivery or external AI calls.

C6 now adds claim/state filters to inventory and export, saved-view vocabulary,
and current active-case claim counts with matching inventory links. A case may
appear under several claims; claim groups are not summed as case totals.
URL and state exports retain claim metadata; report packages include only selected
claims and distinguish observation, suspicion, and manual confirmation. Existing
cases without claims retain their export shape. Private HTTP/DOM artifacts require
an explicit report-only selection, are integrity-checked and bounded, and are
excluded by default along with private claim decision reasons.

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
