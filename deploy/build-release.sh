#!/bin/sh
# Build a release package from a merged main commit.
# Usage, from the repository root: sh deploy/build-release.sh <commit> <previous release> [inactive releases to remove...]
set -eu
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
branch=$(git branch --show-current)
mkdir -p "$out/public/assets"
git archive --format=tar.gz -o "$out/source.tar.gz" "$commit" app artisan bootstrap composer.json composer.lock config database package.json public resources routes
# Build the ignored public/assets files from the release commit itself.
git switch -q --detach "$commit"
trap 'git switch -q "$branch"' EXIT
npm run build > /dev/null
cp public/assets/booking.js public/assets/enhance-selects.js "$out/public/assets/"
cp public/booking.css public/staff.css public/staff-schedule.css "$out/public/"
git switch -q "$branch"
trap - EXIT
tmp=$(mktemp -d)
tar -xzf "$out/source.tar.gz" -C "$tmp"
(cd "$tmp" && find . -type f | sed 's|^\./||' | sort | xargs shasum -a 256) > "$out/source.sha256"
rm -rf "$tmp"
(cd "$out" && shasum -a 256 public/*.css public/assets/*.js > public.sha256)
sed -e "s|@NAME@|$name|g" -e "s|@PREVIOUS@|$previous|g" -e "s|@CLEANUP@|$cleanup|g" "$tools/apply.sh.template" > "$out/apply.sh"
cp "$tools/backup.php" "$out/backup.php"
printf '{\n  "name": "%s",\n  "commit": "%s",\n  "previous": "%s",\n  "cleanup": "%s"\n}\n' "$name" "$commit" "$previous" "$cleanup" > "$out/release.json"
sh -n "$out/apply.sh"
echo "PASS built $out: $(wc -l < "$out/source.sha256" | tr -d ' ') source files from $short; previous $previous"
