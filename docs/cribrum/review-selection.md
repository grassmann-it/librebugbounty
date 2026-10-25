# Review selection and rule feedback

Normal and focus Review show a checkbox for each applicable finding before the
case is confirmed. The same selection is submitted by the button, keyboard
shortcut, swipe, or a native form without JavaScript. Checking a suspicion does
not confirm exploitation. A case-wide assessment does not create XSS execution
proof.

Checked suggestions are adopted with their displayed certainty and source
evidence. Existing manual decisions determine their initial selection. Rejected,
resolved and stale findings are not silently revived by a new proposal.

Removing a proposed check means **this finding does not apply to this case**.
Saving the confirmation records a manual rejection and retains the original
observation and immutable history. Technical observations such as SQL errors
remain distinct from SQL injection suspicion. A rejection is bound to the exact
observation and evidence; new evidence requires a new decision.

The originating rules are also added to **Settings → Rule review queue** as
unreviewed hints. Open the case and inspect the stored evidence before completing
a rule review. A hint or completed review does not change a rule, its activation,
other cases, or the classifier's certainty. Multiple supporting rules remain
attributed. This queue records feedback; it does not train or rewrite detectors.

Skipping, opening a case, changing focus mode, and toggling a checkbox alone do
not save a decision. A stale form or changed observation requires reviewing the
current case again. Assessment, selection, history and rule hints are committed
together.

Explicit rejection of an incorrect observation can leave other supported report
components eligible. It cannot supply missing execution proof or override WAF,
source, freshness or coverage requirements. Ordinary report component exclusion
retains its existing hold semantics. Raw evidence remains private and requires
the existing independent export opt-ins.

The additive review migration is included in the normal application upgrade.
Installing the source does not activate candidate packs or reclassify inventory.
