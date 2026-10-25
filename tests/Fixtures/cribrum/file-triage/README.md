# File/include triage synthetic regression suite

These public fixtures are invented and contain no target captures. They test exact
input-to-target relations in a single PHP diagnostic and required counterexamples.
Run `vendor/bin/phpunit tests/Cribrum/FileTriageDetectorTest.php`.

No qualified real-world positive LFI/RFI corpus has been validated. Passing this
suite is not a claim about dataset precision or recall. The detector is shadow by
default and uses raw-body evidence with exact Unicode spans. DOM-only captures,
other languages' include formats, nested parentheses in target names, arbitrary
stream wrappers and unknown transformations are not qualified automatically.

`file_include_diagnostic` is a hint. `lfi_suspected` / `rfi_suspected` describe an
input-mapped failed include attempt, never arbitrary file access or execution.
Disabled remote wrappers and same-origin remote URLs do not yield RFI suspicion.
`sensitive_file_content_observed` describes a strong content shape separately;
authenticity and its cause remain unproven. Code examples are excluded locally;
unclear documentation outside code elements still needs human review.
