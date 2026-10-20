# C4 local corpus

`corpus-v1.json` is synthetic, schema 1, version `synthetic-2026.10.20-v1`.
Each case contains an `ObservationInput` object, exact expected claim/state map,
optional excluded claims, and positive/negative rule IDs. Pack and classifier
versions are pinned; changing a signature or version requires reviewing fixtures.
The fixture is static and tests never derive expected results from current rules.
Run `ddev exec vendor/bin/phpunit tests/Cribrum --testdox` to see versioned case names.
The default output also reports corpus, pack/core and classifier/core versions.

Every default rule has a matching response and a relevant negative: quoted
signatures on successful documentation pages, or matching errors without input
context. Additional cases cover execution/reflection/encoding, harmless errors,
marketing, code examples, documentation, search results, and empty responses.
ClassifierTest generates bounded repetitive input at each representation limit
and one byte above it rather than storing megabyte fixture files.

No real responses are included. A private corpus must remain outside the tracked
fixtures and be populated only from already retained, authorized observations,
without target requests. Its cases should use the same schema and record expected
claims/states/reasons, original/retained raw and DOM sizes, truncation, and sensitivity.
The synthetic corpus does not establish real-world false-positive rates or private
response size distributions.
