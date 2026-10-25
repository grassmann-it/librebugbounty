#!/usr/bin/env bash
set -euo pipefail
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
source_root=$(cd "$script_dir/../.." && pwd -P)
cd "$source_root"
export STUDIO_BROWSER_ROOT="/tmp/librebugbounty-cribrum-badges-ux11-$(date +%s)-$$"
php "$script_dir/cribrum_badges_browser_router.php" init
port=$(python3 - <<'PY'
import socket
with socket.socket() as sock:
    sock.bind(('127.0.0.1',0))
    print(sock.getsockname()[1])
PY
)
base="http://127.0.0.1:$port"
php -S "127.0.0.1:$port" -t public "$script_dir/cribrum_badges_browser_router.php" >"$STUDIO_BROWSER_ROOT/server.log" 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true' EXIT
ready=0
for i in {1..50}; do
  kill -0 "$server_pid" 2>/dev/null || { cat "$STUDIO_BROWSER_ROOT/server.log"; exit 1; }
  if curl -fsS "$base/__cribrum_badges_fixture" -o "$STUDIO_BROWSER_ROOT/before.json" 2>/dev/null; then ready=1; break; fi
  sleep 0.1
done
[ "$ready" = 1 ] || { cat "$STUDIO_BROWSER_ROOT/server.log"; exit 1; }
python3 - "$STUDIO_BROWSER_ROOT" "$source_root" "$server_pid" <<'PY'
import json,pathlib,sys
root=pathlib.Path(sys.argv[1]); source=pathlib.Path(sys.argv[2]); d=json.loads((root/'before.json').read_text())
assert d['runtime']['sourceRoot']==str(source.resolve()) and d['runtime']['storageRoot']==str(root)
assert d['runtime']['serverPid']==int(sys.argv[3]), 'Response came from a different server process'
assert d['paths']['database']==str(root/'database.sqlite') and (root/'database.sqlite').is_file()
assert d['calls']==[]
(root/'mixed-id').write_text(d['fixtures']['mixed']['id'])
assert 'dense' in d['fixtures'] and d['fixtures']['dense']['id'] != d['fixtures']['mixed']['id']
PY
finding_id=$(cat "$STUDIO_BROWSER_ROOT/mixed-id")
# One passive route per family, plus history anchor and observation selection.
paths=( "/findings/$finding_id" "/findings/$finding_id#verlauf" "/findings/$finding_id/diagnostics" "/review?images=all" "/review/$finding_id/interactive" "/findings" "/findings?view=workbasket" "/findings/$finding_id#kontakt" "/" "/intake/help" "/statistics" "/export" "/settings" "/status" "/errors" "/about" "/docs" )
for path in "${paths[@]}"; do
  kill -0 "$server_pid" 2>/dev/null || { cat "$STUDIO_BROWSER_ROOT/server.log"; exit 1; }
  curl -fsSL "$base$path" -o /dev/null
done
curl -fsS "$base/__cribrum_badges_fixture" -o "$STUDIO_BROWSER_ROOT/after-get.json"
python3 - "$STUDIO_BROWSER_ROOT" <<'PY'
import json,pathlib,sys
root=pathlib.Path(sys.argv[1]); before=json.loads((root/'before.json').read_text()); after=json.loads((root/'after-get.json').read_text())
assert after['snapshot']==before['snapshot'], 'Passive GET changed DB snapshot'
rejected=[call for call in after['calls'] if call['refused']]
mutations=[call for call in after['calls'] if call['category']=='mutation']
assert not rejected, rejected
assert not mutations, mutations
rows={r['finding_id']:json.loads(r['snapshot_json']) for r in after['snapshot']['finding_report_revision']}
ids={name:item['id'] for name,item in after['fixtures'].items()}
mixed=rows[ids['mixed']]; dense=rows[ids['dense']]; waf=rows[ids['waf']]
assert {c['code'] for c in mixed['components']}=={'xss_confirmed','sql_error_observed'}
assert 'sqli_suspected' in mixed['tags'] and 'sqli_suspected' not in {c['code'] for c in mixed['components']}
assert len(dense['tags'])==23 and 'third_party_unmapped_signal' in dense['tags']
assert {'sqli_suspected','lfi_suspected','rfi_suspected'} <= set(dense['tags'])
assert waf['lane']=='xss' and waf['gate']=='hold' and 'new_waf_block_unreviewed' in waf['reasonCodes']
assert {c['code'] for c in waf['components']}=={'xss_confirmed'} and waf['wafReview']['pending']
assert rows[ids['sql-error']]['tags']==['sql_error_observed'] and ids['missing'] not in rows
for name in ['sql-error','include','stale']:
    snap=rows[ids[name]]
    assert snap['caseSummary']['kind']=='unknown' and snap['caseSummary']['purity']=='unknown'
    assert snap['lane']=='none' and snap['gate']=='no_mail' and snap['components']==[]
assert rows[ids['disclosure']]['caseSummary']['kind']=='pure_information_disclosure'
assert rows[ids['waf']]['caseSummary']['kind']=='pure_xss'
assert rows[ids['stale']]['basisRevision'] != next(r['basis_revision'] for r in after['snapshot']['finding_report_context'] if r['finding_id']==ids['stale'])
assert any(r['finding_id']==ids['dense'] for r in after['snapshot']['finding_assessment'])
assert any(r['finding_id']==ids['dense'] for r in after['snapshot']['evidence'])
assert any(r['finding_id']==ids['dense'] for r in after['snapshot']['screenshot_job'])
assert any(r['finding_id']==ids['waf'] for r in after['snapshot']['finding_assessment'])
print('Passive calls by category:', {key:sum(c['category']==key for c in after['calls']) for key in ['status-read','read','mutation']})
PY
curl -fsS -X POST "$base/findings/$finding_id/retest" -o /dev/null
curl -fsS "$base/__cribrum_badges_fixture" -o "$STUDIO_BROWSER_ROOT/after-action.json"
python3 - "$STUDIO_BROWSER_ROOT" <<'PY'
import json,pathlib,sys
root=pathlib.Path(sys.argv[1]); before=json.loads((root/'after-get.json').read_text()); after=json.loads((root/'after-action.json').read_text())
new=after['calls'][len(before['calls']):]
assert len(new)==1 and new[0]['client']=='default' and new[0]['method']=='POST' and new[0]['path']=='/retest' and new[0]['accepted'], new
print('PASS: 17 passive families kept database and worker mutations unchanged; explicit retest logged once')
print('Source root:', after['runtime']['sourceRoot'])
print('Fixture root:', root)
PY
