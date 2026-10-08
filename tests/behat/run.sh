#!/bin/sh
set -eu

repo_root="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
site="${QUANTA_BEHAT_SITE:-behat.local}"
test_password="${QUANTA_BEHAT_PASSWORD:-behat-test-pass}"
doctor_log="${TMPDIR:-/tmp}/quanta-behat-doctor.log"

export QUANTA_BEHAT_SITE="$site"
export QUANTA_BEHAT_PASSWORD="$test_password"
export QUANTA_BEHAT_DOCTOR_LOG="$doctor_log"

rm -rf "$repo_root/sites/$site" "$repo_root/static/tmp/$site"

if ! "$repo_root/doctor" "$site" setup   --web-server-type=2   --web-server-user="$(id -un)"   --profile=generic   --admin-pass="$test_password" >"$doctor_log" 2>&1
then
  cat "$doctor_log" >&2
  exit 1
fi

# Keep the real Doctor/user/database setup, but replace demo content with
# deterministic image-free fixtures so core smoke tests are independent from
# example-media thumbnail behavior.
rm -rf "$repo_root/sites/$site/pages" "$repo_root/sites/$site/_components"
cp -R "$repo_root/tests/behat/fixture/." "$repo_root/sites/$site/"

exec "$repo_root/vendor/bin/behat" -c "$repo_root/tests/behat/behat.yml"
