# Evidence and report handoff contract, policy v2

The transport `schemaVersion` remains **1**; the extended decision semantics are
identified by `policyVersion: "behavior-report-v2"`. A schema or policy version
must be supported and currently active. There is no silent v1-to-v2 conversion.
The [synthetic XSS snapshot](examples/pure-xss-handoff.json) demonstrates the
complete shape. Its invented reference hashes are illustrative, not importable
evidence records or cohort membership. The JSON Schemas in [schemas](schemas/) describe structure; semantic checks,
hashes, local evidence resolution and current source checks remain mandatory.

## Observation evidence and finding-bound facts

[ObservationEvidence v1](schemas/observation-evidence-v1.schema.json) is library
output with `kind: observation_evidence`, `schemaVersion`, `code`, `mode`,
`producer`, `producerVersion`, `artifactSha256`, `spans`, `attributes`, `relations`,
and deterministic `evidenceId`. It also carries compatibility transport fields
`pack`, `version`, `rule`, `representation`, `phrase`, `shadow`; these must agree
with the typed fields. No finding identity or vulnerability claim is implied.
`diagnostics` remains reserved for pack/engine evaluation errors, not observed
SQL or file diagnostics.

A span contains `id`, `representation` (`raw`, `dom_text`, `dialog`),
`representationSha256`, `start`, `length`, `excerpt`, `representationLength`, and
`excerptSha256`. Offsets and lengths count Unicode codepoints, not UTF-8 bytes or
JavaScript UTF-16 units. Hash exact UTF-8 bytes without normalization. Relations
carry `type`, `from`, `to` referencing span IDs and optional named
`transformations`; additional metadata preserves detector-specific explanation.
`artifactSha256: null` means raw was unavailable. Text derived from HTML is not
an actually captured DOM. Empty PHP maps may serialize as `[]`; consumers accept
object or array containers for generic attributes and transformation metadata.

`EvidenceSignal::make()` checks spans against the supplied capture;
`validate()` checks stored structure/integrity, and `validateAgainstInput()` is
required to authenticate a persisted signal against its immutable capture.
`evidenceId` is SHA256 of recursively key-sorted PHP JSON, excluding `evidenceId`,
with unescaped Unicode/slashes and preserved zero fractions. Keep the original
signal bytes for transport; cross-language JSON reserialization is not a general
replacement for the runtime identity implementation.

The application adapter creates `kind: behavior`, `schemaVersion: 1` facts only
after source validation, adding `factId`, `findingId`, `sourceInstanceId`,
`pocRevision`, `pocSha256`, `sourceRef`, `capturedAt`, `producer`, `mode`,
`attributes`, `spans`, `relations`, and `relatedFactIds`. Facts are immutable.
Observation capture time and interpretation time are separate. Hash stored
`fact_json` bytes as `fact_sha256`; do not include that storage hash in its own
hash input. Only active, current, qualified facts enter report policy; shadow is
for evaluation. A core evidence signal must never pretend to be a complete fact.

Capture owners pin `requestContextSha256` before browser work. It hashes exact
PHP JSON `[method, requestParams, payload, expectedEvidence]` with unescaped
Unicode/slashes. Worker proofs and active HTTP facts require that pin to match
the current request; changing a body or payload at the same URL cannot renew
an old proof. Missing historical pins remain unknown. Reclassification preserves
original pins and capture lineage; it never fills them from current finding data.
Unattributed captures may remain inspectable as history/shadow without replacing
the independently selected current evidence.

Coverage has its own `sourceRef` even for captures that produce no facts. It binds
the retained observation ID, compressed artifact SHA256 and exact stored
classification JSON SHA256. A body-less refresh independently reloads that source
and authenticates retained bytes and current request context. Missing/stale
coverage becomes `unknown`, so it cannot recreate `pure_xss`; valid independent
XSS/E2 basis can still permit mail. Missing selected evidence for a composition
fails closed. `sourceRef.captureRef` identifies the original screenshot/retest
run so same-capture execution and WAF context are compared by identity, never by
matching timestamp text. These hashes and references stay internal.

## Snapshot fields

[Report handoff schema](schemas/report-handoff-policy-v2.schema.json) is the
shared reader/producer boundary. The persisted snapshot includes:

| Fields | Meaning |
|---|---|
| `schemaVersion`, `policyVersion` | Supported transport and active decision semantics |
| `sourceInstanceId`, `findingId` | Stable source identity and local finding identity; a DB path is not identity |
| `decisionRevision`, `basisRevision` | Immutable decision revision and exact revision of its inputs |
| `intake` | `schemaVersion`, `sourceKind`, `sourceRef`, `declaredClaims`, `payloadKind`, `payloadSource`, `markerSource`, `legacyCohortId`; source/intent is not proof |
| `poc` | Original `intakeUrl`, selected `effectiveUrl`, `revision`, SHA256 `sha256` |
| `legacyBasis` | Null or explicit fixed cohort ID/digest and `baselinePocRevision`/`baselinePocSha256`; verified membership is adapter responsibility |
| `claims` | Claim, state, source, revision, `reportSelected`, observation reference; claims are source inputs, not rewritten by this pure policy |
| `factIds`, `tags` | Current decision's selected active immutable facts and stable derived tags |
| `selection` | Component decisions with `component`, `mode: auto/include/exclude`, origin, revision, actor and reason |
| `reportSelection` | Entire report `mode: auto/exclude` plus decision provenance |
| `lane`, `components` | Composition and qualified components, each with code, certainty and evidence refs |
| `gate`, `reasonCodes`, `reviewRequired` | `eligible/no_mail/hold`, stable reasons and actionable review state |
| `urgencyAllowed`, `decidedAt` | SQL-basis permission for optional urgency; producer timestamp (pure policy can return null before persistence) |
| `caseSummary`, `coverage` | Shared type, purity and evaluation scope described below |
| `wafBlockReview`, `wafBlockFingerprint` | Pending review, accepted acknowledgement, current/pending semantic fingerprints |
| `workflow` | Optional object with source workflow context; readers still re-read live lifecycle/contact/fix blocks |

Arrays with set semantics are sorted and deduplicated; components have unique
codes. Certainties are fixed: `xss_confirmed=confirmed`, `sqli_suspected`,
`lfi_suspected`, `rfi_suspected=suspected`, `information_disclosure` and
`sql_error_observation=observed`. They do not mutate claim states. File/include
components never assert successful file reading or code execution. If a requested
mail composition lacks supported mail semantics it is held, not silently dropped.

Each evidence ref contains `kind`, `id`, `sourceInstanceId`, `findingId`,
`pocRevision`, `pocSha256`, `contentSha256`. Resolve only explicitly selected local
refs: `behavior_fact` and `worker_proof` resolve immutable fact JSON (worker proofs
require `execution_observed` and the full proof); `legacy_member` resolves the
stored fixed membership JSON. Hash the original stored bytes before decoding.
Never resolve arbitrary filesystem paths supplied by a DTO.

Persist a source singleton (`report_source_contract`), revisioned finding context
(`finding_report_context`), immutable facts (`finding_behavior_fact`), immutable
snapshots (`finding_report_revision`) and a current read-only view
(`finding_report_current`). Hash original `snapshot_json` UTF-8 bytes as
`snapshot_sha256`, not a reserialized object. Indexed SQL columns must agree with
validated snapshot contents. A reader holds on mismatch, stale basis, source,
PoC, claim-revision set, hash, unsupported schema or inactive policy. Producer
writes compare basis again atomically before commit. Any gate-relevant change
increments basis atomically with its source update, including marker/payload,
claim/proof/active evidence, selection and WAF acknowledgement changes. Shadow-only
changes do not by themselves change the active basis. Failed refresh does not
leave an old eligible decision usable.

Queue, Extract and Reuse read source, current claims and projection in one SQLite
read transaction. Reuse, generation/review, validation and export recheck each
bundle member's source revision and current workflow blocks. A frozen snapshot
hash cannot override a later fixed/contacted state. Do not keep a database
transaction open across an external operation. Store exact PoC bytes in an
immutable `poc.raw.txt`; changed inputs create a new case revision, never overwrite
existing raw files. Scope and frozen snapshot belong in the case/review hash.

## E1–E7 invariants

- **E1:** Qualified XSS plus SQL suspicion or SQL-error observation forms one
  combined message. Excluding a qualified part holds the combination; no silent
  partial message. SQL-error observation alone does not establish SQLi.
- **E2:** Only the explicitly frozen cohort with matching baseline PoC keeps its
  legacy XSS basis despite missing capture/proof or automatic no-hit/stale.
  No default public membership, migration-source enrollment or later-PoC inheritance.
- **E3:** Curate clear evidence; escalate decision-relevant ambiguity for review.
  Scores, AI agreement and incomplete mappings do not substitute for proof.
- **E4:** Describe SQL injection as suspicion; urgency requires concrete SQL basis
  and remains optional. Payload co-occurrence is insufficient.
- **E5:** Standalone disclosure needs concrete internal query/path/stack/config
  details with evidence; generic errors, product names and documentation do not qualify.
- **E6:** New XSS requires attributed worker execution. Automatic policy is
  `native_marker_cs_substring_v1`: one native dialog contains the stored marker,
  case-sensitive, no normalization; marker has at least three Unicode codepoints
  and two distinct characters. Hook-only, arbitrary dialogs or unqualified
  `still_vulnerable` require review. Reviewed existing execution proof records
  actor, reason and source; no execution is invented from reflection or type choice.
- **E7:** A newly evidenced relevant WAF block on established XSS creates mail hold
  until explicit fingerprint-bound acknowledgement, including E2. Basis remains.
  Continuing the same block deduplicates review; later success does not silently
  resolve an unseen pending cause. A product name alone is not a block.

`eligible` is not send authorization. Existing contact, ledger, lifecycle,
recipient-text and review rules remain additional requirements. A hold starts no
network test. OBB selection remains manual; purity filters are not OBB bans.

## Type, purity, coverage and selection

`caseSummary` contains `kind`, `purity: pure/mixed/unknown`, component-code list,
`hints` and `reviewReasons`. The same projection serves UI and report-gen;
consumers do not run a separate scoring policy. `pure_xss` is supported by
report-gen. Queue/Extract expose the kind and components **before** manual model
and reasoning selection. No pack, classifier or policy chooses a model/provider.
Internal IDs, codes, provenance and model choices never become recipient prose.

`coverage` names `profile`, integer `version`, `requiredFamilies`,
`evaluatedFamilies`, `status: complete/partial/unknown`, `pocRevision`,
`pocSha256`, `artifactSha256`, `resultVersion` and `mode` (`active` required for purity).
Artifact/result values must equal the authoritative current context values.
Current profile is `behavior-triage-v1` version 1 (string `"1"` is also
accepted; `triage-v1` is an accepted profile alias).
Required families are `disclosure`, `local_include`, `reflection`,
`remote_include`, `sql`, `waf`. The four registered detectors map to these six
required semantic families: reflection → reflection; SQL → sql; file →
local_include/remote_include; context → disclosure/waf (plus the optional
`error_response` evaluation family). The complete shipped candidate pack is
`behavior-triage` **1.5.1**. Reflection coverage requires actual execution of
`reflection-triage-v1`; built-in marker handling is not a substitute. An old
three-detector pack is partial and rejected for explicit active mode.
Completeness requires their actual evaluation and
current artifact, result and PoC binding. Unknown/stale profile or missing binding
is unknown; missing families or incomplete capture are partial. No-hit is not
coverage. A supported-detection profile cannot rule out all unknown vulnerabilities.

New candidate output from `cribrum-1.1.x` requires additive
`coverage.evaluation`, validated by [Evaluation coverage v1](schemas/evaluation-coverage-v1.schema.json).
It has `schemaVersion: 1` and a deterministic `runs` list. Each run binds
`pack`, pack `version`, `rule`, versioned `detector`, `mode`, selected `outputs`
and `familyOutcomes`. Each family records `status`, all applicable `stages`,
and nonnegative `candidatesConsidered`, `signalsQualified`, `candidatesExcluded`
counters. Stage identity is `stage` plus nullable `representation`; each stage
records its own `status` and `issues`. Optional input absent from an applicable
raw-only profile does not itself create an unavailable stage.

Stage/family statuses are `completed`, `limited`, `unavailable`, `failed`.
Family aggregation uses `failed > limited > unavailable > completed`; issues
are retained independently. Issues bind `code`, `detector`, `family`, `stage`,
`representation`, optionally numeric/null `counters`, `operation`, `pcreCode` or
an original-bound private `candidate` span reference. A candidate must match a
non-null stage representation. A request-value stage with null representation
may retain its independently valid raw/DOM diagnostic candidate reference.
Unknown remaining counts
are null. Optional `evaluation.engineIssues` records core preparation failures
with `code`, `stage`, `representation` and optional regex operation/error code.
Optional engine issue `status` is `failed` (the default when omitted) or
`limited`. Actual query-variable/nesting resource truncation is `limited`; it
records optional nonnegative/null `counters`, prevents complete coverage while
preserving parsed values and existing limits,
and creates no engine-failure diagnostic or failure exit by itself.
It is execution metadata, never an evidence signal, fact or recipient field.
It contains no active evidence IDs. The schema describes structure; PHP also
checks detector/family and issue bindings, selected outputs, duplicate identities,
status precedence, pack-version agreement, and the mandatory stage inventory
for the concrete registered v1 detector identities. Conditional candidate work
is required when applicable; no-hit inputs do not invent candidate stages.

Every selected run must finish every declared applicable family, including
repeated families across packs. Valid partial evidence remains usable when a
later independent stage fails. Any non-completed family or engine issue prevents
complete coverage. Missing, malformed or unsupported execution details cannot
certify completeness; newly instrumented result construction reports a contract
diagnostic and downgrades an attempted `complete` to `partial`. Unknown future
objects and extension fields survive JSON roundtrips unchanged. Validation changes
only the derived effective status/diagnostics; reading and projection refresh never
rewrite the original stored classification JSON or its hash. Historical
`cribrum-1.0.0` results without details stay readable without invented runs or
completed statuses. Core-only runs do not synthesize candidate evaluation.

Partial/unknown coverage excludes strict purity filters. It alone creates no mail
hold or E7 episode; independent XSS/E2, concrete disclosure and qualified suspicion
continue under existing evidence policy. CLI JSON includes complete execution
details. Text and JSON warnings identify partial/unknown evaluation. Exit `0`
means a successful invocation, including limits; engine diagnostics return `1`,
and invalid invocation/active preconditions return `2`. Consumers must check
coverage rather than interpreting exit `0` as complete.

| Scope | Selection meaning |
|---|---|
| `pure_xss` | Valid XSS basis, complete current profile, no extra SQL/include/other-type hints or open context |
| `xss_containing` | Broad XSS selection including combined cases; never an alias for pure XSS |
| `disclosure_only` | Qualified standalone disclosure, complete current profile, no XSS/SQLi/include suspicion or open extra context |
| `sqli_containing` | Qualified SQL suspicion, including combinations |
| `all_supported` | Supported compositions subject to evidence, gate and workflow checks |

Optional `allowedComponents` narrows scope without granting proof. Check every
bundle member and every component; exclusions must not shorten an E1 combination.
E2 with unknown coverage can remain mail-eligible while failing `pure_xss`.
Unknown purity is not a global mail hold. Scope never relaxes evidence gates.

## Core matcher reuse

From `cribrum-1.1.1`, the shipped Core pack shares identical matching work within
one classification call. The ordered key consists of `representation`, `any`,
`all`, `excludes`, `context` and `minStatus`. Array order is significant: selected
phrases and first exclusion reasons remain observable. Only successful match or
no-match returns are reused; exceptions retain their per-rule handling.

Each rule still emits its own signal, metadata and Explain row and participates
in the existing claim/score/provenance merge. Shared matching is not another
independent confirmation and adds no score. The shipped pack has 88 rules and
60 complete matcher definitions; this reduces matcher invocations, not outputs.
It does not imply a proportional wall-clock speedup.

Reuse is authorized by the repository's actual shipped Core object, not by a
pack's name or equivalent manifest bytes. Injected/private packs, registered
detectors and builtins retain their separate executions. The cache never survives
the current input/call. Pack bytes, evidence identities and report policy stay
unchanged; implementation version stamps and their derived result hashes change.
