"""Offline cross-language acceptance: reads only the PHP test's temporary database."""
import json
import sqlite3
import sys
from dataclasses import asdict
from report_gen import handoff
from report_gen.composition import recipient_case, component_paragraphs, mail_errors, scoped_section

path, finding_id, action, scope = sys.argv[1:]
assert 'librebugbounty-tests-' in path, 'refusing non-test database'
db = sqlite3.connect('file:' + path + '?mode=ro', uri=True)
db.row_factory = sqlite3.Row
try:
    db.execute('BEGIN')
    finding = dict(db.execute('SELECT * FROM finding WHERE id=?', (finding_id,)).fetchone())
    snapshot, raw = handoff.read_behavior_handoff(db, finding, scope)
    result = {'caseSummary': snapshot['caseSummary'], 'components': snapshot['components'],
              'behaviorNotice': handoff.behavior_notice(snapshot),
              'poc': snapshot['poc']['effectiveUrl']}
    if action == 'compose':
        context = recipient_case(snapshot, 'combined.fixture.invalid')
        result['paragraphs'] = component_paragraphs(context, 'de')
        result['leaks'] = mail_errors('Sicherheitsmeldung\n\nxss_payload_in_sql_error', (), context)
        result['safe'] = mail_errors('Sicherheitsmeldung\n\n' + result['paragraphs'], (), context)
    if action == 'source_canary':
        # Deliberately supply actual private facts as extra projection data. The
        # recipient allowlist must ignore them; author text stays independently safe.
        facts = [json.loads(row['fact_json']) for row in db.execute(
            'SELECT fact_json FROM finding_behavior_fact WHERE finding_id=?', (finding_id,))]
        context = recipient_case(dict(snapshot, privateEvidence=facts), 'source.fixture.invalid')
        result['recipientContext'] = asdict(context)
        result['paragraphs'] = component_paragraphs(context, 'de')
        fields = {'location': 'Die betroffene Antwort enthält technische Details.',
                  'impact': 'Interne Implementierungsdetails sind öffentlich einsehbar.',
                  'observation': 'Die Antwort enthält einen zusammenhängenden Quelltextblock.',
                  'cause': 'Serverseitiger Quelltext wird als Antwort ausgeliefert.',
                  'remediation': 'Bitte korrigieren Sie die Ausgabe und die Kontextkodierung.'}
        if 'xss_confirmed' in context.components:
            fields['mechanism'] = 'Die unabhängige Browserbeobachtung belegt JavaScript-Ausführung.'
        result['mail'] = scoped_section(context, fields, 'de', snapshot['poc']['effectiveUrl'])
        result['safe'] = mail_errors(result['mail'], (snapshot['poc']['effectiveUrl'],), context)
    print(json.dumps(result, ensure_ascii=False))
except ValueError as error:
    print(json.dumps({'error': str(error)}))
finally:
    db.close()
