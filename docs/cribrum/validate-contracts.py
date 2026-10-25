#!/usr/bin/env python3
"""Validate public Cribrum contracts offline. Requires jsonschema >= 4.18."""
import copy
import json
from pathlib import Path

from jsonschema import Draft202012Validator

BASE = Path(__file__).resolve().parent
ROOT = BASE.parents[1]


def read(path):
    return json.loads(path.read_text(encoding="utf-8"))


def main():
    validators = {}
    for path in sorted((BASE / "schemas").glob("*.json")):
        schema = read(path)
        Draft202012Validator.check_schema(schema)
        validators[path.name] = Draft202012Validator(schema)
    examples = [
        ("rule-pack-v2.schema.json", BASE / "examples/contributor-triage.json", None),
        ("rule-pack-v2.schema.json", ROOT / "resources/cribrum/candidates/behavior-triage.json", None),
        ("report-handoff-policy-v2.schema.json", BASE / "examples/pure-xss-handoff.json", None),
        ("observation-evidence-v1.schema.json", ROOT / "tests/Fixtures/cribrum/evidence-signal-v1.json", "evidence"),
    ]
    for schema, path, key in examples:
        example = read(path)
        validators[schema].validate(example[key] if key else example)

    pack = read(BASE / "examples/contributor-triage.json")
    invalid_packs = []
    for key, value in [("detector", "unregistered-detector"), ("outputs", ["invented_output"]),
                       ("shadow", False), ("claim", "xss")]:
        invalid = copy.deepcopy(pack)
        invalid["rules"][0][key] = value
        invalid_packs.append(invalid)
    for invalid in invalid_packs:
        assert not validators["rule-pack-v2.schema.json"].is_valid(invalid), "Invalid pack accepted"

    reflection = {"id": "synthetic.reflection", "detector": "reflection-triage-v1", "outputs": ["input_reflected"]}
    registry_check = copy.deepcopy(pack)
    registry_check["rules"] = [reflection]
    validators["rule-pack-v2.schema.json"].validate(registry_check)
    reflection["outputs"] = ["sql_error_observed"]
    assert not validators["rule-pack-v2.schema.json"].is_valid(registry_check), "Cross-detector output accepted"
    print(f"{len(validators)} Draft 2020-12 schemas, {len(examples)} shipped examples, 6 registry/negative checks passed.")
    print("Structural validation only: runtime evidence/source/revision and coverage checks remain required.")


if __name__ == "__main__":
    main()
