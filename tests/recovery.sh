#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
tag="clinic-recovery-$(openssl rand -hex 4)"
work="$(mktemp -d "${TMPDIR:-/tmp}/clinic-recovery.XXXXXX")"
pw="$(openssl rand -hex 24)"
cleanup() { docker rm -fv "$tag" >/dev/null 2>&1 || true; rm -rf "$work"; }
trap cleanup EXIT
export APP_ENV=testing CACHE_STORE=array SESSION_DRIVER=array
export APP_CONFIG_CACHE="$work/config.php"
export APP_KEY="base64:$(openssl rand -base64 32)"
# Only a newly created, disposable local container is ever used.
docker run --rm -d --name "$tag" -p 127.0.0.1::3306 -e MYSQL_ROOT_PASSWORD="$pw" mysql:8.4 >/dev/null
export DB_HOST=127.0.0.1 DB_PORT="$(docker port "$tag" 3306 | cut -d: -f2)"
export DB_DATABASE=clinic_recovery_source DB_USERNAME=root DB_PASSWORD="$pw"
ready=false
for n in {1..60}; do
  if docker exec -e MYSQL_PWD="$pw" "$tag" mysql -uroot -e 'SELECT 1' >/dev/null 2>&1; then ready=true; break; fi
  sleep 1
done
if [ "$ready" != true ]; then echo 'FAIL: disposable MySQL did not become ready'; exit 1; fi
docker exec -e MYSQL_PWD="$pw" "$tag" mysql -uroot -e 'CREATE DATABASE clinic_recovery_source; CREATE DATABASE clinic_recovery_restored;'
php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (!$app->environment("testing") || config("database.default") !== "mysql" || config("database.connections.mysql.host") !== "127.0.0.1" || (string)config("database.connections.mysql.port") !== getenv("DB_PORT") || config("database.connections.mysql.database") !== "clinic_recovery_source") { fwrite(STDERR,"Refusing non-disposable recovery target.\n"); exit(1); }'
php artisan migrate --force >/dev/null
php tests/head-office.php
php artisan db:seed --force >/dev/null
php tests/recovery-fixture.php create
# Database contains the encrypted clinical records and HR attachment bytes.
docker exec -e MYSQL_PWD="$pw" "$tag" mysqldump -uroot --single-transaction --no-tablespaces --set-gtid-purged=OFF clinic_recovery_source > "$work/database.sql"
test -s "$work/database.sql"
openssl dgst -sha256 "$work/database.sql" > "$work/checksum.txt"
export DB_DATABASE=clinic_recovery_restored
docker exec -i -e MYSQL_PWD="$pw" "$tag" mysql -uroot clinic_recovery_restored < "$work/database.sql"
php tests/recovery-fixture.php verify
echo 'PASS recovery: disposable MySQL dump/import; matching table counts and encrypted rows; patient, clinical record and HR attachment decrypt; wrong key rejected. Production was not accessed.'
