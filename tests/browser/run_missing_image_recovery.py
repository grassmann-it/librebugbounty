#!/usr/bin/env python3
"""Run missing-image recovery in disposable SQLite/storage with a local fixture server."""
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import uuid

project = Path(__file__).resolve().parents[2]
root = Path(tempfile.gettempdir()) / f"librebugbounty-studio-{uuid.uuid4().hex}"
env = os.environ.copy()
env['STUDIO_BROWSER_ROOT'] = str(root)
assert sys.argv[1:] in ([], ['--diagnostics'])
suite = 'studio-diagnostics.cjs' if sys.argv[1:] else 'studio-missing-image-recovery.cjs'
env['STUDIO_BROWSER_OUTPUT'] = str(project / 'var' / f'preferences-acceptance-recovery-{uuid.uuid4().hex}')
with socket.socket() as sock:
    sock.bind(('127.0.0.1', 0))
    port = sock.getsockname()[1]
env['STUDIO_BROWSER_BASE'] = f'http://127.0.0.1:{port}'
# The host ships SQLite as an optional module; DDEV already enables it.
php = ['php']
if subprocess.check_output(['php', '-r', 'echo extension_loaded("pdo_sqlite") ? "yes" : "no";'], text=True) == 'no':
    php += ['-d', 'extension=pdo_sqlite']
router = 'tests/Support/studio_browser_router.php'
server = None
try:
    subprocess.run(php + [router, 'init'], cwd=project, env=env, check=True)
    with tempfile.TemporaryFile(mode='w+') as log:
        server = subprocess.Popen(php + ['-S', f'127.0.0.1:{port}', '-t', 'public', router], cwd=project, env=env, stdout=log, stderr=subprocess.STDOUT)
        for _ in range(100):
            if server.poll() is not None:
                raise RuntimeError('Fixture server exited')
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=0.2):
                    break
            except OSError:
                time.sleep(0.1)
        try:
            subprocess.run(['node', f'tests/browser/{suite}'], cwd=project, env=env, check=True, timeout=90)
        except Exception:
            log.seek(0)
            print(log.read())
            raise
finally:
    if server is not None:
        server.terminate()
        try:
            server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait()
    if root.exists():
        shutil.rmtree(root)
