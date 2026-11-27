# Production deployment

Production runs on a DirectAdmin host with no SSH access.
These tools upload a release through the DirectAdmin file manager API and run it once through a temporary cron job.
The site sits behind Cloudflare, so pages load stylesheets and scripts through content-versioned URLs (`App\Support\Asset`).

## Files

| File | Purpose |
|---|---|
| `build-release.sh` | Builds a release package under `storage/releases/main-<commit>/` from a merged `main` commit. |
| `apply.sh.template` | Server-side script, rendered into each package as `apply.sh`. |
| `backup.php` | Server-side private database dump taken before the entry switch. |
| `deploy.py` | Local client: uploads the package, runs `apply.sh`, checks the live site and rolls back on failure. |
| `task.py` | Local client: runs one allow-listed maintenance task on the live release after a database backup. |

`storage/` is ignored by git, so packages, logs and backups never enter the repository.

## Local configuration

This repository is public.
Keep the server address and panel account out of it.
Create `storage/releases/deploy-config.json` on the deploying machine:

```json
{
  "site_host": "<public site host>",
  "panel_ip": "<server IP behind Cloudflare>",
  "panel_port": 2221,
  "panel_user": "<DirectAdmin user>"
}
```

## Release steps

1. Merge the pull request into `main`.
2. Build the package. Pass the live release name and any inactive release directories to remove after a successful switch.

```sh
git fetch origin
sh deploy/build-release.sh origin/main main-<live commit>
```

A release that adds migrations must be built with `--migrate`; without it, `apply.sh` stops when a migration is pending:

```sh
sh deploy/build-release.sh --migrate origin/main main-<live commit>
```

3. Run the read-only check. It signs in, confirms the live entry and scheduler, and saves the live `index.php` and stylesheets to `previous-public/`.

```sh
python3 deploy/deploy.py storage/releases/main-<new commit> --check
```

4. Deploy.

```sh
python3 deploy/deploy.py storage/releases/main-<new commit>
```

Each run asks for the DirectAdmin password at a hidden prompt.
The password stays in process memory and is never written anywhere.

## Maintenance tasks

`task.py` runs one task from a fixed list against the live release; it never takes a shell command.

| Task | Runs |
|---|---|
| `demo-seed` | `DemoDataSeeder`: 50 tagged demonstration patients with bookings, visits, receipts, packages, stored value, inventory, HR and inbox data. |
| `demo-purge` | `DemoDataPurgeSeeder`: removes that tagged data set. |

```sh
python3 deploy/task.py demo-seed --check
python3 deploy/task.py demo-seed
```

The server dumps the database to `~/clinic-releases/tasks/<task id>/pre-task-backup.sql`, then runs the seeder with `CLINIC_DEMO_DATA=production` for that one process.
The seeders refuse production without that variable, and the variable never enters `.env`.
Demonstration bookings carry no email and no WhatsApp consent, so no notification reaches the demonstration phone numbers.
The demonstration branches and their timetable appear on the public booking pages.
The log is saved to `storage/releases/tasks/<task id>/task.log`.

## What a deployment does

1. Uploads the package to `~/clinic-releases/<release>/` and the new script asset to `public_html/assets/`.
2. Creates a one-time cron job that runs `apply.sh`, waits for it, then deletes the cron job.
3. `apply.sh` extracts the source, reuses the previous `vendor` through hard links, links the shared `storage` and `.env`, verifies every source checksum and dumps the database to `pre-deploy-backup.sql`.
   With pending migrations, it runs them only for a `--migrate` package, then confirms none is left; otherwise it stops.
   It then compiles Blade views and lists routes.
4. `apply.sh` points `public_html/index.php` at the new release, which is the switch.
5. The client uploads the stylesheets to `public_html` and checks the live site through Cloudflare: status codes, and every versioned asset each page references against the package content.
6. If any check fails, the client restores the previous `index.php` and stylesheets.
7. Only after the checks pass, the client moves the `schedule:run` cron job to the new release.

## Limits

- Migrations run while the previous release is still live, before the switch. A migration must keep the previous release working: add tables and nullable columns; never drop or rename what the live code still reads.
- A rollback after a migration restores code only. The schema stays migrated; undoing it means restoring `pre-deploy-backup.sql`, which also loses data written since the backup.
- A failed migration leaves the previous release live and prints the migration output; restore `pre-deploy-backup.sql` if the schema changed partway.
- `CLINIC_PHP` overrides the PHP path only for a local rehearsal; the server leaves it unset.
- The server's `mysqldump` rejects `--set-gtid-purged`, so `backup.php` does not pass it.
- `public_html/index.php` references the release by a path relative to the home directory, so `apply.sh` matches `/clinic-releases/<release>/public/index.php` without `$HOME`.
- Rollback after the scheduler move is manual: upload `previous-public/index.php` and the stylesheets, then point the scheduler cron job back.
