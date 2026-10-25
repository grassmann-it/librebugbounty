#!/usr/bin/env bash
set -euo pipefail
cd /var/www/html/var/uxpilot-ui-implementation-20261025/sol-03
export STUDIO_BROWSER_ROOT="/tmp/librebugbounty-cribrum-badges-ux2-sol03-$(date +%s)-$$"
php tests/Support/cribrum_badges_browser_router.php init >/dev/null
php -S 127.0.0.1:18098 -t public tests/Support/cribrum_badges_browser_router.php >"$STUDIO_BROWSER_ROOT/server.log" 2>&1 &
pid=$!
trap 'kill "$pid" 2>/dev/null || true' EXIT
sleep .4
finding_id=$(php -r '$a=json_decode(file_get_contents(getenv("STUDIO_BROWSER_ROOT")."/fixtures.json"),true);echo $a["mixed"]["id"];')
curl -fsS "http://127.0.0.1:18098/findings/$finding_id/diagnostics" -o "$STUDIO_BROWSER_ROOT/diagnostics.html"
curl -fsS "http://127.0.0.1:18098/findings/$finding_id" -o "$STUDIO_BROWSER_ROOT/finding.html"
python3 - <<'PY'
import os,pathlib
root=pathlib.Path(os.environ['STUDIO_BROWSER_ROOT'])
d=(root/'diagnostics.html').read_text(); f=(root/'finding.html').read_text()
assert 'diagnostic-summary' in d and 'data-stored-classification' in d and 'data-current-classification' in d
assert 'synthetic-sql-error-context' in d
import re
match=re.search(r'Aktuelle lokale Auswertung</h3><p>(\d+) Vorschläge · (\d+) Signale', d)
assert match and int(match.group(1)) > 0 and int(match.group(2)) > 0
assert 'studio-history-orientation' in f and 'id="verlauf"' in f
print('PASS UX2 diagnostics/history render', root)
PY
