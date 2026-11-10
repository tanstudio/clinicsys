"""Deploy a prepared Clinic release package to production through the DirectAdmin API.

Usage, from the repository root:
  python3 deploy/deploy.py storage/releases/<release> [--check]

The package directory comes from deploy/build-release.sh and holds release.json, apply.sh, backup.php,
source.tar.gz, source.sha256, public files and public.sha256.

Connection details live in storage/releases/deploy-config.json, which git ignores, because this
repository is public. See deploy/README.md for its fields.

The panel password is typed at a getpass prompt and used for HTTP Basic authentication. It stays in
this process memory only; it is never printed, logged or written to disk. --check runs every
read-only check and stops before any change.
"""

import getpass
import hashlib
import json
import re
import socket
import sys
import time
from pathlib import Path

import requests

CONFIG_FILE = Path('storage/releases/deploy-config.json')
if not CONFIG_FILE.is_file():
    raise SystemExit(f'Missing {CONFIG_FILE}. Create it as deploy/README.md describes; never commit it.')
server = json.loads(CONFIG_FILE.read_text())
HOST, PANEL_IP, PANEL_PORT, USER = server['site_host'], server['panel_ip'], int(server['panel_port']), server['panel_user']
ROOT = f'https://{HOST}:{PANEL_PORT}'
SITE = f'https://{HOST}'
PUBLIC = f'/domains/{HOST}/public_html'
PHP = '/usr/local/php82/bin/php'

args = [a for a in sys.argv[1:] if not a.startswith('--')]
CHECK_ONLY = '--check' in sys.argv
HERE = Path(args[0]).resolve()
config = json.loads((HERE / 'release.json').read_text())
NAME, PREVIOUS_NAME = config['name'], config['previous']
RELEASE, PREVIOUS = '/clinic-releases/' + NAME, '/clinic-releases/' + PREVIOUS_NAME

# The site name resolves to Cloudflare; the panel connection goes to the server IP directly.
_getaddrinfo = socket.getaddrinfo
socket.getaddrinfo = lambda host, *a, **kw: _getaddrinfo(PANEL_IP if host == HOST and a and a[0] == PANEL_PORT else host, *a, **kw)

session = requests.Session()
session.trust_env = False


def say(message):
    print(message, flush=True)


def call(method, path, **kwargs):
    r = session.request(method, ROOT + path, timeout=90, **kwargs)
    if r.status_code >= 300:
        raise RuntimeError(f'{method} {path} -> HTTP {r.status_code} {r.text[:300]}')
    return r


def post(path, data):
    return call('POST', path, json=data)


def download(path):
    return call('GET', '/api/filemanager/download', params={'path': path}).content


def exists(path):
    return session.get(ROOT + '/api/filemanager/stat', params={'path': path}, timeout=30).ok


def upload(directory, name, data, perm='0600', overwrite=False):
    params = {'dir': directory, 'name': name, 'perm': perm}
    if overwrite:
        params['overwrite'] = 'true'
    return call('POST', '/api/filemanager-actions/upload', params=params, files={'file': (name, data)})


def sha(data):
    return hashlib.sha256(data).hexdigest()


def crons():
    rows = call('GET', '/CMD_CRON_JOBS', params={'json': 'yes'}).json().get('crons', {})
    return [r for r in rows.values() if isinstance(r, dict)]


# ---- Local package checks -------------------------------------------------------------------
files = {name: (HERE / name).read_bytes() for name in ['source.tar.gz', 'source.sha256', 'apply.sh', 'backup.php']}
files['booking.js'] = (HERE / 'public/assets/booking.js').read_bytes()
files['enhance-selects.js'] = (HERE / 'public/assets/enhance-selects.js').read_bytes()
public_files = {p: (HERE / 'public' / p).read_bytes() for p in ['booking.css', 'staff.css', 'staff-schedule.css', 'assets/enhance-selects.js']}
expected = {line.split()[1]: line.split()[0] for line in (HERE / 'public.sha256').read_text().splitlines()}
for p, data in public_files.items():
    assert sha(data) == expected['public/' + p], 'Local package changed: ' + p
assert sha(files['booking.js']) == expected['public/assets/booking.js'], 'Local booking.js changed'
assert ('name=' + NAME).encode() in files['apply.sh'] and ('/clinic-releases/' + PREVIOUS_NAME).encode() in files['apply.sh'], 'apply.sh does not match release.json'
source_count = len((HERE / 'source.sha256').read_text().splitlines())
# Versioned URLs embed the first 12 hex digits of each file's sha256.
by_version = {sha(data)[:12]: (p, data) for p, data in list(public_files.items()) + [('assets/booking.js', files['booking.js'])]}
say(f'PASS local package {NAME}: {source_count} source files, 5 public files match public.sha256')

# ---- Sign in and read-only checks ------------------------------------------------------------
session.auth = (USER, getpass.getpass(f'DirectAdmin password for {USER}: '))
entry_before = download(PUBLIC + '/index.php')
assert (PREVIOUS + '/public/index.php').encode() in entry_before, 'public_html/index.php does not point to ' + PREVIOUS
say('PASS signed in; live entry points to ' + PREVIOUS)
assert not exists(RELEASE), RELEASE + ' already exists; inspect it before deploying again'
assert exists(PREVIOUS + '/vendor'), 'Previous release vendor directory missing'
scheduler_old = f'cd "$HOME{PREVIOUS}" && {PHP} artisan schedule:run >> /dev/null 2>&1'
scheduler_new = f'cd "$HOME{RELEASE}" && {PHP} artisan schedule:run >> /dev/null 2>&1'
schedulers = [r for r in crons() if 'artisan schedule:run' in r.get('command', '')]
assert len(schedulers) == 1 and schedulers[0]['command'] == scheduler_old, 'Expected exactly one scheduler on the previous release'
say('PASS one scheduler cron on the previous release')

backup_dir = HERE / 'previous-public'
backup_dir.mkdir(exist_ok=True)
previous_public = {}
for p in ['booking.css', 'staff.css', 'staff-schedule.css']:
    previous_public[p] = download(f'{PUBLIC}/{p}')
    (backup_dir / p).write_bytes(previous_public[p])
(backup_dir / 'index.php').write_bytes(entry_before)
say('PASS saved live index.php and 3 stylesheets to ' + str(backup_dir))
if CHECK_ONLY:
    say('CHECK ONLY: no change made.')
    sys.exit(0)

# ---- Upload and apply ------------------------------------------------------------------------
post('/api/filemanager-actions/mkdir', {'path': RELEASE})
for name, data in files.items():
    upload(RELEASE, name, data)
say(f'PASS uploaded {len(files)} files to {RELEASE}')
# New asset files first: the old pages never reference them, so this changes nothing visible yet.
upload(PUBLIC + '/assets', 'enhance-selects.js', public_files['assets/enhance-selects.js'], perm='0644', overwrite=True)
say('PASS uploaded public_html/assets/enhance-selects.js')

command = f'cd "$HOME{RELEASE}" && if [ ! -f started ]; then touch started; /bin/sh apply.sh > apply.log 2>&1 || touch failed; fi'
call('POST', '/CMD_API_CRON_JOBS', data={'action': 'create', 'json': 'yes', 'minute': '*', 'hour': '*',
    'dayofmonth': '*', 'month': '*', 'dayofweek': '*', 'command': command})
job = next((r.get('id') for r in crons() if r.get('command') == command), None)
assert job, 'Temporary deployment cron was not created'
say('Waiting for the one-time deployment cron (runs each minute)...')
try:
    for _ in range(90):
        if exists(RELEASE + '/done') or exists(RELEASE + '/failed'):
            break
        time.sleep(4)
finally:
    call('POST', '/CMD_API_CRON_JOBS', data={'action': 'delete', 'json': 'yes', 'select0': job})
    assert not any(r.get('command') == command for r in crons()), 'Temporary cron still present'
    say('PASS temporary cron removed')
log = download(RELEASE + '/apply.log').decode(errors='replace') if exists(RELEASE + '/apply.log') else ''
(HERE / 'apply.log').write_text(log)
say(log.strip())
if not exists(RELEASE + '/done'):
    raise SystemExit('FAIL apply.sh did not finish. The live entry was not switched unless apply.log shows the entry PASS line.')

entry_after = download(PUBLIC + '/index.php')
assert (RELEASE + '/public/index.php').encode() in entry_after, 'Entry was not switched'
verified = download(RELEASE + '/verification.log').decode().strip().splitlines()
assert len(verified) == source_count and all(v.endswith(': OK') for v in verified), 'Source verification incomplete'
say(f'PASS {len(verified)} of {source_count} source files verified on the server; entry switched')

for p in ['booking.css', 'staff.css', 'staff-schedule.css']:
    upload(PUBLIC, p, public_files[p], perm='0644', overwrite=True)
say('PASS uploaded 3 stylesheets to public_html')


# ---- Smoke test through Cloudflare -----------------------------------------------------------
def smoke():
    plain = requests.Session()
    lines, healthy = [], True
    for path, want in [('/', 200), ('/staff/login', 200), ('/availability', 200), ('/.env', 403)]:
        got = plain.get(SITE + path, timeout=30, allow_redirects=False).status_code
        healthy &= got == want
        lines.append(f'{"PASS" if got == want else "FAIL"} {path} -> {got} (expected {want})')
    # The pages must reference this release's versioned files, and each versioned URL must serve that content.
    pages = {'/': 3, '/staff/login': 2}
    for page, minimum in pages.items():
        urls = sorted(set(re.findall(r'(?:href|src)="(/[^"?]+\.(?:css|js))\?v=([0-9a-f]{12})"', plain.get(SITE + page, timeout=30).text)))
        ok = len(urls) >= minimum and all(v in by_version and '/' + by_version[v][0] == u for u, v in urls)
        healthy &= ok
        lines.append(f'{"PASS" if ok else "FAIL"} {page} references {len(urls)} versioned assets of this release')
        for u, v in urls:
            r = plain.get(f'{SITE}{u}?v={v}', timeout=30)
            match = r.status_code == 200 and v in by_version and sha(r.content) == sha(by_version[v][1])
            healthy &= match
            lines.append(f'{"PASS" if match else "FAIL"} {u}?v={v} -> {r.status_code}, cache {r.headers.get("cf-cache-status", "-")}')
    return healthy, lines


healthy, lines = smoke()
for line in lines:
    say(line)
if not healthy:
    upload(PUBLIC, 'index.php', entry_before, perm='0644', overwrite=True)
    for p, data in previous_public.items():
        upload(PUBLIC, p, data, perm='0644', overwrite=True)
    raise SystemExit('FAIL smoke test. Rolled back index.php and the 3 stylesheets; the scheduler still uses the previous release.')

# ---- Scheduler -------------------------------------------------------------------------------
old = [r for r in crons() if r.get('command') == scheduler_old]
call('POST', '/CMD_API_CRON_JOBS', data={'action': 'create', 'json': 'yes', 'minute': '*', 'hour': '*',
    'dayofmonth': '*', 'month': '*', 'dayofweek': '*', 'command': scheduler_new})
call('POST', '/CMD_API_CRON_JOBS', data={'action': 'delete', 'json': 'yes', 'select0': old[0]['id']})
schedulers = [r for r in crons() if 'artisan schedule:run' in r.get('command', '')]
assert len(schedulers) == 1 and schedulers[0]['command'] == scheduler_new, 'Scheduler cron not switched'
say('PASS scheduler cron points to ' + RELEASE)

(HERE / 'deployment.json').write_text(json.dumps({**config, 'release': RELEASE, 'previous_release': PREVIOUS,
    'verified_source_files': len(verified), 'smoke': lines, 'scheduler_aligned': True,
    'database_backup': RELEASE + '/pre-deploy-backup.sql',
    'rollback': 'Upload previous-public/index.php and the 3 stylesheets to public_html, then point the scheduler back.'}, indent=2))
say('DONE release ' + NAME + ' is live.')
