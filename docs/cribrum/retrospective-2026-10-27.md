# Cribrum retrospective — 2026-10-27

Repeated misses shared gaps in request-input handling, response representation
and the connection between detector tests and user-visible outcomes. They were
not all missing signatures. Focused fixes covered the examples already examined,
while the checks did not establish general detection quality across unseen
families. The latest examined miss added a PHQL/ORM parser family that the native
SQL paths did not support. Its scoped correction does not establish that all SQL
patterns are solved.

The key distinction remains evidence strength. A database diagnostic, an input
relationship, a suspected SQL-injection hint, a structural indication and report
eligibility are different results. Some missing triage hints needed corrections;
withholding stronger facts without their required evidence is intentional. The
strict structural and report-policy guards must remain.

## Shared causes behind the SQL misses

| Layer | What went wrong | Consequence |
|---|---|---|
| Request values | Core `queryValues()` drops array values after PHP query parsing. The strict detector separately treats bracketed keys as ambiguous. | A value could be present in the original request but unavailable to a core hint, or deliberately ineligible for unique structural attribution. These are different reasons for an absent result. |
| Request-value provenance | Core request values came only from the URL query string and an explicit payload field. A payload placed in the URL path — the common local vector — produced no request value at all, so the SQL paths returned before they inspected the response. | A source that visibly contains the value still produced no hint, because the value never entered the classifier. Provenance of a request value is separate from the rules that may use it. |
| Carrier shape | A debug page can echo one complete statement across several lines inside `<p class="…">`. The single-line candidates stopped at the first line, the paragraph candidate required an attribute-free `<p>`, and the generic candidate stopped at reflected markup. | No carrier matched, so neither query disclosure nor any input relationship existed. A carrier fix alone does not supply the input relationship. |
| Local association | The legacy SQL hint requires the full request value on the same response line. | Native error excerpts that omit leading punctuation, wrap lines or retain only a suffix could miss that path despite containing useful triage evidence. |
| Native grammar | The first correction required a specific SQLSTATE wrapper and allowed at most four omitted prefix characters; later examined forms needed a wrapper-free native message and up to eight, with the retention ratio still enforced. | A fix for one serialization did not cover the neighboring native format. |
| Representation | HTML stripping, raw markup, captured DOM text and locally entity-encoded diagnostics do not preserve the same bytes, boundaries or offsets. | A relationship visible in one representation could disappear in another. Unnamed transformations also make a match difficult to authenticate against the original source. |
| Carrier completeness | An unclosed HTML container can still contain a recognizable native error. | Treating incomplete carrier context as absence of any diagnostic loses an observation; treating it as complete proof would overstate the evidence. Those decisions need separate handling. |
| Query language and clause | The latest missed response contained Phalcon PHQL, an inline `when parsing:` query and an `ORDER BY` input. The native detector lacked its signature and query boundary; HTML stripping lost input markup, and its structural guards did not support PHQL placeholders or that operand position. | A signature-only change would still miss. This family needs a coherent raw-preserving ORM parser/input path, without labelling it a native database error or weakening structural report facts. |

The [core paths](../../src/Cribrum/CribrumClassifier.php) and
[strict SQL detector](../../src/Cribrum/Detector/SqlTriageDetector.php) currently
apply different request parsing and qualification rules. The strict ambiguity
guard protects against false associations. The open design problem is preserving
all request leaves and their provenance before deciding which evidence level they
can support, rather than making every parser discard the same uncertain data.

The [1.3.5 rule card](rule-cards/native-sql-suspicion-1.3.5.md) documents the
implemented narrow core fallbacks: native MySQL/MariaDB suffix handling with
bounded transformations, and a specific PDO query/ordered-array relationship.
They produce suspicion without new structural SQLi facts or report eligibility.
Their narrowness also creates an overfitting risk: the 80-byte clipped-fragment
shape and exact PDO wrapper/query grammar may fit examined serializations more
closely than a broader diagnostic family. That risk has not been resolved by an
unseen-family evaluation. Another response outside those shapes can still miss.

## The 2026-11-09 correction and what it does not establish

Engine 1.3.8 addresses the two causes above for one inspected family: decoded
path request values, a bounded multi-line statement carrier, and one suspicion
helper that observes a quoting-carrying request value retained verbatim inside a
disclosed statement. Path values deliberately stay outside the core context
needles, because a reflected path alone must not qualify a page-wide error line.

The correction is narrow by construction. It does not read entity-encoded
reflections, it does not evaluate request values without SQL quoting characters,
and it does not change the strict detector, candidate packs, active mode or
report policy. It has not been evaluated against an unseen family set, and the
measurements below still apply unchanged.

## What the existing measurements establish

The following are measurements of the checked public fixture files, not estimates
of real-world precision or recall:

| Measurement | Result | Limit |
|---|---|---|
| [Core corpus](../../tests/Fixtures/cribrum/corpus-v1.json) | 187 cases; 131 distinct complete input objects; 68 distinct non-null `rawHtml` values | Complete-input uniqueness includes every input field. Raw-value uniqueness excludes missing/null values and includes empty text. Neither count establishes independent templates or vulnerability families. **68 raw values are not 68 independent families.** |
| [Public detector matrix](../../tests/Fixtures/cribrum/public-regression-v2.json) | 27 `detectorCases`; zero array-key inputs; zero `domText` inputs; only two inputs explicitly set HTTP status | Separate policy cases are outside this count. The detector rows did not exercise important request/representation/status combinations behind the later misses. |
| [Candidate matrix adapter](../../tests/Cribrum/CandidateEngineTest.php) | Checks stored expected typed evidence codes/attributes, then compares shadow suggestions with the current core baseline | That public-matrix route has no independently expected core-suggestion oracle. Core and shadow can agree while both omit the desired hint. |
| [Core corpus tests](../../tests/Cribrum/ClassifierTest.php) | Assert stored expected core claims and rule coverage | These are real expectations, not merely parity checks. Their presence does not demonstrate independent label authorship, unseen-family coverage or generalization beyond the selected cases. |
| [Frozen evaluator](../../src/Cribrum/Evaluation/FrozenCorpusEvaluator.php) | Authenticates retained inputs, records implementation hashes, checks supplied expectations and compares baseline/shadow/activation-preview modes | All modes use the current engine class. It does not execute a historical engine. It records split/family labels without enforcing development/holdout separation or family disjointness. |

Frozen bytes and hashes make a run reproducible. Mode parity checks compatibility.
Expected-code assertions test the named semantics. None substitutes for an
independent end-to-end expectation about the core claim, displayed certainty and
report gate for each input. Large assertion counts do not supply that missing
accuracy denominator.

The [contributor guidance](contributing.md) already requires expectations written
from semantics, independent labels, separate holdout families and caution about
duplicate templates and precision/recall claims. The gap was not missing written
advice. We have not demonstrated an enforced unseen-family holdout or global
recall for this delivery.

## The Intake bugs had a similar coverage gap

The repeated-URL failure occurred with paste-to-submit disabled: native Ctrl+V
into an already populated URL field appended the same URL, producing `U + U`.
Tests that filled the field or pasted once did not exercise that interaction.
The correction suppresses an exact repeat of the current full URL without
submitting or canonicalizing it; different URL and partial edits retain their
native behavior. The [paste regressions](../../tests/browser/studio-intake-paste.cjs)
now cover the repeated native interaction.

Global history merged stored-save receipts, but did not retain the duplicate
receipt overlay. A duplicate could therefore lose its **Already exists** label
and existing-case link, particularly around the server's latest-50 boundary and
refresh/reload. The missing combination was global history × duplicate receipt ×
inside/outside that boundary × refresh/reload. The
[history regressions](../../tests/browser/studio-intake-session.cjs) now cover it
without adding personal progress or repeating the save.

Similar content on different hostnames was a separate observation: content
aliases do not establish a failure of exact-URL deduplication. Changing that key
would require a separate product decision and could merge distinct PoCs.

## Implemented corrections and their limits

The 1.3.5 delivery added focused native/PDO suspicion regressions, raw-span and
transformation checks, explicit active-mode/report-boundary checks, and the two
Intake interaction regressions. The scoped results are recorded in the
[release preparation notes](../../.github/releases/v2.2.0.md). They validate the
named cases and contracts.

Engine 1.3.6 adds a separate
[PHQL parser/input path](rule-cards/phql-input-suspicion-1.3.6.md), with an exact
query byte count, adjacent closed parser trace, unique complete request binding
and unquoted `ORDER BY` context. It preserves raw spans and emits suspicion only;
it does not assert a native SQL error or execution. The new
[policy matrix](../../tests/Cribrum/SqlSuspicionPolicyMatrixTest.php) explicitly
specifies claims, selected typed facts and report gates for seven canonical or
binding-negative inputs across three modes. It is a focused correction to the
oracle gap, not the full cross-product matrix or an independent family holdout.

That delivery also recorded nine actual AI source readings. The
[source-reading ledger](contracts-v2.md#source-reading-receipts) coordinates
workers and binds receipts to inspected bytes, context and review policy. It
shows which work was completed. It does not turn a reading into independent
ground truth, a vulnerability confirmation or evidence of broad classifier
quality. Its outcomes must not be used as a recall metric.
The PHQL correction adds one actual source reading, bringing that inspected set
to ten; it does not change those limits.

## Later source audit and carrier corrections

The subsequent retained-source audit actually inspected another 463 sources and
recorded seven scoped additions supported by the existing engine. That reading
also found unsupported native syntax carriers: a serialized MariaDB alert and a
localized Access query expression. Recording a `potential_gap` receipt did not
fix either family. Engine 1.3.7 implements their separate source-level
observation/input paths; a reading marker must not be presented as a fix marker.

Two later user examples exposed PHP result/resource failures: an uncaught
mysqli TypeError ending an open HTML document, and rendered mysqli warnings.
The fatal message's semantic kind was already supported, but no existing
carrier passed it to that reader. Error observations now use a bounded native
fatal/warning path. Neither a boolean instead of a result nor an independently
reflected XSS input establishes a SQL input relationship; emitting SQLi
suspicion for those examples would change the certainty policy rather than fix
the missing error observation.

Two PostgreSQL OFFSET examples exposed another split: a SQL error was already
recognized internally but hidden behind the generic error badge. Their reflected
request value appeared in later request dumps rather than in the complete query.
The correction exposes the source-bound SQL-error cause and recognizes a bounded
native `query was:` disclosure before the large trace. It does not infer an input
relationship from the surrounding dump, and retains the independent trace limit.
Cause labels now share revision/freshness checks across summaries, filters and
exports. This was a visibility and query-carrier gap, not missing SQLi proof.

Another class of apparently missing SQL results was historical request binding.
Some older sources already produce error/suspicion during current offline
evaluation, while their original capture lacks the request-context hash. Replay
deliberately cannot update current claims from those bytes. Existing capture
rows in the examined legacy set did not recover the missing pins. Today's
method/body/payload must not be used to manufacture capture-time evidence. This
is a history/qualification boundary, not a new regex miss. It also means a
copy-list filter based only on stored claims can miss a visible SQL diagnostic;
the bounded source scan found and removed one such case from a corrected local
export. An absent SQL match is not proof of SQL-free content.

The [1.3.7 card](rule-cards/native-carriers-and-runtime-1.3.7.md) documents the
implemented carriers and limits. The systemic work below remains open; these
family corrections are not a family-separated holdout or a general accuracy
measurement.

## Prioritized open work

1. **OPEN — Add an explicit per-mode end-to-end expectation matrix.** For core,
   candidate shadow and explicit active mode, specify expected claims and
   certainty, typed facts, coverage, displayed interpretation and report gate.
   Include the high-risk combinations of scalar/array/duplicate inputs,
   raw/DOM/entity/multiline representations, HTTP status and incomplete carriers.
   Assert required absences as well as positives; a suspected core hint must not
   silently become a structural fact or qualified report component.
   The 1.3.6 policy matrix supplies a small executable starting point; broader
   representation/input/coverage combinations and displayed UI outcomes remain
   open.

2. **OPEN — Define a shared lossless request-leaf/transformation/provenance
   contract.** Preserve original keys, order, array paths, duplicate occurrences,
   source identity and named decoding steps. Readers should share those inputs
   while applying explicit, separate certainty rules. Preserving an ambiguous
   leaf must not grant it unique attribution or structural proof. Evidence must
   map back to the original captured representation.

3. **OPEN — Expose structured helper skip reasons and cover them in explain
   output.** Distinguish unsupported input shape, ambiguous binding, missing or
   incomplete context, representation mismatch, limits, grammar mismatch and
   insufficient proof. Register the core helper routes alongside detector
   explanations so a missing result can be traced through the actual mode and
   path instead of inferred from a generic no-hit.

4. **OPEN — Evaluate independently labelled, family-separated holdouts.** Group
   duplicate bodies/templates before splitting, freeze the development set and
   enforce exclusion of holdout families from tuning. Specify labels before
   inspecting the implementation's output and report per-family/mode outcomes,
   errors and unknowns. Historical-engine comparisons require actual pinned
   implementations, not a mode named baseline. Only an evaluation with a defined
   population and independent ground truth can support a broader recall claim.

These actions remain open. Adding this retrospective changes no classifier,
activation, assessment or report behavior.
