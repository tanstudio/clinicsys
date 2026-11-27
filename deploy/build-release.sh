#!/bin/sh
# Build a release package from a merged main commit.
# Usage, from the repository root: sh deploy/build-release.sh [--migrate] <commit> <previous release> [inactive releases to remove...]
# --migrate allows apply.sh to run pending migrations, after the database backup and before the entry switch.
set -eu
migrate=0
if [ "${1:-}" = "--migrate" ]; then migrate=1; shift; fi
commit=$(git rev-parse --verify "$1^{commit}")
previous=$2
shift 2
cleanup="$*"
short=$(printf %s "$commit" | cut -c1-12)
name="main-$short"
out="storage/releases/$name"
tools="deploy"
[ ! -e "$out" ] || { echo "FAIL $out already exists"; exit 1; }
[ -z "$(git status --porcelain --untracked-files=no)" ] || { echo "FAIL tracked changes present; commit or stash them first"; exit 1; }
# Return to the starting point afterwards: the branch, or the exact commit when HEAD was detached.
branch=$(git branch --show-current)
start=$(git rev-parse HEAD)
switched=0
built=0
restore() {
  if [ -n "$branch" ]; then git switch -q "$branch"; else git switch -q --detach "$start"; fi
  switched=0
}
# On any failure, go back and remove the partial package, so the next attempt is not refused as "already exists".
# The checks above run before this trap, so an existing package is never removed.
finish() {
  status=$?
  if [ "$switched" = 1 ]; then restore; fi
  if [ "$built" != 1 ] && [ -n "$out" ]; then rm -rf "$out"; fi
  exit "$status"
}
trap finish EXIT
mkdir -p "$out/public/assets"
git archive --format=tar.gz -o "$out/source.tar.gz" "$commit" app artisan bootstrap composer.json composer.lock config database package.json public resources routes
# Build the ignored public/assets files from the release commit itself.
git switch -q --detach "$commit"
switched=1
npm run build > /dev/null
cp public/assets/booking.js public/assets/enhance-selects.js "$out/public/assets/"
cp public/booking.css public/staff.css public/staff-schedule.css "$out/public/"
restore
tmp=$(mktemp -d)
tar -xzf "$out/source.tar.gz" -C "$tmp"
(cd "$tmp" && find . -type f | sed 's|^\./||' | sort | xargs shasum -a 256) > "$out/source.sha256"
rm -rf "$tmp"
(cd "$out" && shasum -a 256 public/*.css public/assets/*.js > public.sha256)
sed -e "s|@NAME@|$name|g" -e "s|@PREVIOUS@|$previous|g" -e "s|@CLEANUP@|$cleanup|g" -e "s|@MIGRATE@|$migrate|g" "$tools/apply.sh.template" > "$out/apply.sh"
cp "$tools/backup.php" "$out/backup.php"
printf '{\n  "name": "%s",\n  "commit": "%s",\n  "previous": "%s",\n  "cleanup": "%s",\n  "migrate": %s\n}\n' "$name" "$commit" "$previous" "$cleanup" "$([ $migrate = 1 ] && echo true || echo false)" > "$out/release.json"
sh -n "$out/apply.sh"
built=1
echo "PASS built $out: $(wc -l < "$out/source.sha256" | tr -d ' ') source files from $short; previous $previous; migrations $([ $migrate = 1 ] && echo allowed || echo refused)"
