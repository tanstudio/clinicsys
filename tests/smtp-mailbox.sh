#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
tag="clinic-verify-$(openssl rand -hex 3)"
pw="$(openssl rand -hex 18)"
trap 'docker rm -fv "$tag-db" "$tag-mail" >/dev/null 2>&1 || true' EXIT
docker run --rm -d --name "$tag-mail" -p 127.0.0.1::1025 -p 127.0.0.1::8025 axllent/mailpit:v1.27 >/dev/null
docker run --rm -d --name "$tag-db" -p 127.0.0.1::3306 -e MYSQL_RANDOM_ROOT_PASSWORD=yes -e MYSQL_DATABASE=clinic_smtp_qa -e MYSQL_USER=checker -e MYSQL_PASSWORD="$pw" mysql:8.4 >/dev/null
export TEST_SMTP_PORT="$(docker port "$tag-mail" 1025 | cut -d: -f2)" TEST_MAILBOX_PORT="$(docker port "$tag-mail" 8025 | cut -d: -f2)"
export DB_HOST=127.0.0.1 DB_PORT="$(docker port "$tag-db" 3306 | cut -d: -f2)" DB_DATABASE=clinic_smtp_qa DB_USERNAME=checker DB_PASSWORD="$pw" APP_ENV=testing CACHE_STORE=array
for n in {1..75}; do if php -r 'new PDO("mysql:host=127.0.0.1;port=".getenv("DB_PORT").";dbname=clinic_smtp_qa","checker",getenv("DB_PASSWORD"));' >/dev/null 2>&1; then break; fi; sleep 1; done
php artisan migrate --seed --force >/dev/null
php tests/smtp-mailbox.php
