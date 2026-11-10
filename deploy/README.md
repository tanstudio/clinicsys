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

## What a deployment does

1. Uploads the package to `~/clinic-releases/<release>/` and the new script asset to `public_html/assets/`.
2. Creates a one-time cron job that runs `apply.sh`, waits for it, then deletes the cron job.
3. `apply.sh` extracts the source, reuses the previous `vendor` through hard links, links the shared `storage` and `.env`, verifies every source checksum, stops if a migration is pending, dumps the database, compiles Blade views and lists routes.
4. `apply.sh` points `public_html/index.php` at the new release, which is the switch.
5. The client uploads the stylesheets to `public_html` and checks the live site through Cloudflare: status codes, and every versioned asset each page references against the package content.
6. If any check fails, the client restores the previous `index.php` and stylesheets.
7. Only after the checks pass, the client moves the `schedule:run` cron job to the new release.

## Limits

- `apply.sh` refuses to run a release with a pending migration. A migrating release needs an explicit migration step first.
- The server's `mysqldump` rejects `--set-gtid-purged`, so `backup.php` does not pass it.
- `public_html/index.php` references the release by a path relative to the home directory, so `apply.sh` matches `/clinic-releases/<release>/public/index.php` without `$HOME`.
- Rollback after the scheduler move is manual: upload `previous-public/index.php` and the stylesheets, then point the scheduler cron job back.
