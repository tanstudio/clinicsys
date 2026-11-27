"""Run one allow-listed maintenance task against the live Clinic release through the DirectAdmin API.

Usage, from the repository root:
  python3 deploy/task.py <task> [--check]

Tasks:
  demo-seed   Adds the demonstration data set (DemoDataSeeder) to the production database.
  demo-purge  Removes the demonstration data set (DemoDataPurgeSeeder) from the production database.

The server first takes a private database dump into the task directory, then runs the seeder once with
CLINIC_DEMO_DATA=production set for that process only. The variable never enters .env.
Connection details come from storage/releases/deploy-config.json, as for deploy.py. The panel password is typed at
a getpass prompt and stays in this process memory only. --check signs in, confirms the live release and stops.
Logs are saved to storage/releases/tasks/<task id>/.
"""

import getpass
import json
import re
import socket
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

import requests

TASKS = {'demo-seed': 'DemoDataSeeder', 'demo-purge': 'DemoDataPurgeSeeder'}
CONFIG_FILE = Path('storage/releases/deploy-config.json')
args = [a for a in sys.argv[1:] if not a.startswith('--')]
if len(args) != 1 or args[0] not in TASKS:
    raise SystemExit('Usage: python3 deploy/task.py <' + '|'.join(TASKS) + '> [--check]')
TASK, CHECK_ONLY = args[0], '--check' in sys.argv
if not CONFIG_FILE.is_file():
    raise SystemExit(f'Missing {CONFIG_FILE}. Create it as deploy/README.md describes; never commit it.')
server = json.loads(CONFIG_FILE.read_text())
HOST, PANEL_IP, PANEL_PORT, USER = server['site_host'], server['panel_ip'], int(server['panel_port']), server['panel_user']
ROOT = f'https://{HOST}:{PANEL_PORT}'
PUBLIC = f'/domains/{HOST}/public_html'
PHP = '/usr/local/php82/bin/php'
TASK_ID = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-' + TASK
TASK_DIR = '/clinic-releases/tasks/' + TASK_ID
LOCAL = Path('storage/releases/tasks') / TASK_ID

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


def download(path):
    return call('GET', '/api/filemanager/download', params={'path': path}).content


def exists(path):
    return session.get(ROOT + '/api/filemanager/stat', params={'path': path}, timeout=30).ok


def upload(directory, name, data):
    call('POST', '/api/filemanager-actions/upload', params={'dir': directory, 'name': name, 'perm': '0600'}, files={'file': (name, data)})


def crons():
    rows = call('GET', '/CMD_CRON_JOBS', params={'json': 'yes'}).json().get('crons', {})
    return [r for r in rows.values() if isinstance(r, dict)]


session.auth = (USER, getpass.getpass(f'DirectAdmin password for {USER}: '))
entry = download(PUBLIC + '/index.php').decode()
found = re.findall(r"/clinic-releases/([A-Za-z0-9._-]+)/public/index\.php", entry)
assert len(found) == 1, 'Could not read the live release from public_html/index.php'
LIVE = found[0]
assert exists(f'/clinic-releases/{LIVE}/database/seeders/{TASKS[TASK]}.php'), f'{TASKS[TASK]} is missing from the live release {LIVE}'
assert 'CLINIC_DEMO_DATA' in download(f'/clinic-releases/{LIVE}/database/seeders/DemoDataSeeder.php').decode(), \
    f'The live release {LIVE} predates the production guard; deploy first'
say(f'PASS signed in; live release {LIVE} has {TASKS[TASK]} with the production guard')
if CHECK_ONLY:
    say('CHECK ONLY: no change made.')
    sys.exit(0)

script = f'''set -eu
umask 077
php={PHP}
task="$HOME{TASK_DIR}"
release="$HOME/clinic-releases/{LIVE}"
cd "$task"
[ ! -f done ] || exit 0
echo "START {TASK} $(date -u +%FT%TZ)"
grep -q "/clinic-releases/{LIVE}/public/index.php" "$HOME{PUBLIC}/index.php"
echo "PASS live entry still references {LIVE}"
"$php" backup.php "$release" "$task/pre-task-backup.sql"
cd "$release"
if ! CLINIC_DEMO_DATA=production "$php" artisan db:seed --class='Database\\Seeders\\{TASKS[TASK]}' --force --no-interaction > "$task/output.log" 2>&1; then
  cat "$task/output.log"
  echo "FAIL {TASKS[TASK]}; restore $task/pre-task-backup.sql if the data must be rolled back"
  exit 1
fi
cat "$task/output.log"
echo "PASS {TASKS[TASK]} finished"
touch "$task/done"
'''
if not exists('/clinic-releases/tasks'):
    call('POST', '/api/filemanager-actions/mkdir', json={'path': '/clinic-releases/tasks'})
call('POST', '/api/filemanager-actions/mkdir', json={'path': TASK_DIR})
upload(TASK_DIR, 'task.sh', script.encode())
upload(TASK_DIR, 'backup.php', Path('deploy/backup.php').read_bytes())
say(f'PASS uploaded task.sh and backup.php to {TASK_DIR}')

command = f'cd "$HOME{TASK_DIR}" && if [ ! -f started ]; then touch started; /bin/sh task.sh > task.log 2>&1 || touch failed; fi'
call('POST', '/CMD_API_CRON_JOBS', data={'action': 'create', 'json': 'yes', 'minute': '*', 'hour': '*',
    'dayofmonth': '*', 'month': '*', 'dayofweek': '*', 'command': command})
job = next((r.get('id') for r in crons() if r.get('command') == command), None)
assert job, 'Temporary task cron was not created'
say('Waiting for the one-time task cron (runs each minute)...')
try:
    for _ in range(150):
        if exists(TASK_DIR + '/done') or exists(TASK_DIR + '/failed'):
            break
        time.sleep(4)
finally:
    call('POST', '/CMD_API_CRON_JOBS', data={'action': 'delete', 'json': 'yes', 'select0': job})
    assert not any(r.get('command') == command for r in crons()), 'Temporary cron still present'
    say('PASS temporary cron removed')
LOCAL.mkdir(parents=True, exist_ok=True)
log = download(TASK_DIR + '/task.log').decode(errors='replace') if exists(TASK_DIR + '/task.log') else ''
(LOCAL / 'task.log').write_text(log)
say(log.strip())
if not exists(TASK_DIR + '/done'):
    raise SystemExit(f'FAIL {TASK} did not finish. The database backup is {TASK_DIR}/pre-task-backup.sql on the server.')
(LOCAL / 'task.json').write_text(json.dumps({'task': TASK, 'seeder': TASKS[TASK], 'release': LIVE, 'task_dir': TASK_DIR,
    'database_backup': TASK_DIR + '/pre-task-backup.sql'}, indent=2))
say(f'DONE {TASK} on {LIVE}. Log saved to {LOCAL}/task.log')
