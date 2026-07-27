#!/bin/bash
#
# ── Quanta CMS container entrypoint ───────────────────────────────────────────
# Site-agnostic first-boot setup for a Quanta container, run as root before the
# CMD (supervisord: qdbd + php-fpm + nginx) takes over:
#
#   * PHP error display, driven by IS_PRODUCTION
#   * the quanta_db extension kill switch (QUANTA_DB_ENABLED)
#   * the site .env, the writable directories Quanta expects (db/_users,
#     db/_translations, the jobs queue, static/tmp/…) and the derived-data dir
#     qdbd and the extension share
#   * host-alias symlinks so one site dir serves every name it is reached under
#   * the ownership fixes php-fpm (www-data) needs on all of the above
#   * `doctor <site> clear_cache` + `check`
#
# Nothing here is application specific: everything is derived from QUANTA_SITE.
# Downstream application images that need extra start-up steps drop a *.sh file
# into /docker-entrypoint.d/ (see "Hooks" below) rather than replacing this
# script — replacing it means re-implementing all of the above.
#
# Environment:
#   QUANTA_DIR         Quanta source root                (default /var/www/quanta)
#   QUANTA_SITE        canonical site dir under sites/   (default localhost)
#   IS_PRODUCTION      true/1 → PHP errors off, else on  (default: on, i.e. dev)
#   QUANTA_DB_ENABLED  0/off/false/no → unload quanta_db.so and run the legacy
#                      filesystem access paths           (default 1)
#   SITE_ALIASES       comma-separated extra hostnames symlinked to the site
#
# Hooks:
#   Every /docker-entrypoint.d/*.sh is **sourced** (not executed), in shell glob
#   order, after the generic setup and before the ownership pass and the exec.
#   Sourcing means a hook can `export` variables into supervisord's environment
#   (the way the QUANTA_DB_ENABLED switch exports QUANTA_DB_DAEMON below), and
#   that `set -e` still applies — a failing hook aborts the container start.
#   Anything a hook creates under the site dir is picked up by the ownership
#   pass that follows, so hooks do not need to chown what they write.
set -e

QUANTA_DIR="${QUANTA_DIR:-/var/www/quanta}"
QUANTA_SITE="${QUANTA_SITE:-localhost}"
SITE_DIR="$QUANTA_DIR/sites/$QUANTA_SITE"

case "$IS_PRODUCTION" in
    true|TRUE|1)
        {
            echo 'display_errors = Off'
            echo 'display_startup_errors = Off'
        } > /usr/local/etc/php/conf.d/zz-display-errors.ini
        ;;
    *)
        {
            echo 'display_errors = On'
            echo 'display_startup_errors = On'
        } > /usr/local/etc/php/conf.d/zz-display-errors.ini
        ;;
esac

# quanta_db extension kill switch. Set QUANTA_DB_ENABLED=0 to run the app the
# legacy way: we remove the extension's php.ini so the .so is never loaded, and
# the PHP shims detect the missing QuantaDb class and fall back to the legacy
# filesystem access paths.
case "${QUANTA_DB_ENABLED:-1}" in
    false|FALSE|0|off|no|OFF|NO)
        echo "quanta_db extension DISABLED (QUANTA_DB_ENABLED=$QUANTA_DB_ENABLED); using legacy file access."
        rm -f /usr/local/etc/php/conf.d/quanta-db.ini
        # With no extension mapping the segment, the daemon has nothing to serve.
        # supervisord is exec'd from this script, so this export reaches its
        # qdbd-run.sh wrapper, which then exits cleanly instead of starting qdbd.
        export QUANTA_DB_DAEMON=off
        ;;
esac

# The image bakes the canonical site dir into quanta-db.ini (quanta_db.root, read
# by the extension) and QUANTA_DB_ROOT (read by qdbd and qdbstat) at the default
# site name. Re-point both when the site is named something else, so extension,
# daemon and this script all agree on one site dir.
if [ "$QUANTA_SITE" != "localhost" ]; then
    if [ -f /usr/local/etc/php/conf.d/quanta-db.ini ]; then
        sed -i "s#^quanta_db.root=.*#quanta_db.root=$SITE_DIR#" \
            /usr/local/etc/php/conf.d/quanta-db.ini
    fi
    export QUANTA_DB_ROOT="$SITE_DIR"
fi

# Link or create .env for the site. A root-level .env (mounted secret, compose
# env file) wins; otherwise seed one from the site's env.example if it ships one.
if [ -f "$QUANTA_DIR/.env" ]; then
    ln -sf "$QUANTA_DIR/.env" "$SITE_DIR/.env"
elif [ ! -f "$SITE_DIR/.env" ] && [ -f "$SITE_DIR/env.example" ]; then
    echo "Creating .env from env.example..."
    cp "$SITE_DIR/env.example" "$SITE_DIR/.env"
fi

# Ensure writable directories exist. Quanta resolves users and translations under
# db/ (Environment->dir['users'] = db/_users, Localization = db/_translations), so
# they live inside the db volume. Do NOT create the old top-level _users /
# _translations dirs: nodePath() locates node folders with `find docroot/`, and a
# stale empty docroot/_users alongside db/_users yields a duplicate-folder warning
# and ambiguous resolution.
mkdir -p "$SITE_DIR/db/_users"
mkdir -p "$SITE_DIR/db/_translations"
mkdir -p "$QUANTA_DIR/static/tmp/$QUANTA_SITE/files"
mkdir -p "$QUANTA_DIR/static/tmp/$QUANTA_SITE/thumbs"

# PHP session directory. When running multiple pods this is a shared volume
# (hostPath) so all pods read/write the same sessions. The mount is created
# root-owned, so ensure the php-fpm workers (www-data) can write to it.
mkdir -p /var/lib/php/sessions
chown www-data:www-data /var/lib/php/sessions

# Job queue directories (JobsFactory / Job::DIR_*) are hostPath mounts created
# root-owned; the php-fpm workers write to them during requests, so make sure
# www-data owns them.
for d in _jobs_todo _jobs_done _jobs_unknown; do
    mkdir -p "$SITE_DIR/jobs/$d"
    chown www-data:www-data "$SITE_DIR/jobs/$d"
done

# Symlink common host aliases so Quanta and the nginx per-host aliases (/files/,
# /assets/, /thumbs/, ... all resolve through sites/$host) work for every name
# the site is reached under. Stale real directories from previous runs are
# replaced with symlinks.
ALIASES=""
if [ "$QUANTA_SITE" = "localhost" ]; then
    # Dev conveniences for the default site name: the loopback IP, plus the
    # ":8080" host:port forms. nginx's $host carries no port, so those exist
    # only for PHP-side lookups that read HTTP_HOST verbatim.
    ALIASES="127.0.0.1 localhost:8080 127.0.0.1:8080"
fi
if [ -n "$SITE_ALIASES" ]; then
    for extra in $(echo "$SITE_ALIASES" | tr ',' ' '); do
        ALIASES="$ALIASES $extra"
    done
fi

for alias in $ALIASES; do
    target="$QUANTA_DIR/sites/$alias"
    if [ ! -L "$target" ]; then
        rm -rf "$target"
        ln -s "$QUANTA_SITE" "$target"
    fi
    tmp_target="$QUANTA_DIR/static/tmp/$alias"
    if [ ! -L "$tmp_target" ]; then
        rm -rf "$tmp_target"
        ln -s "$QUANTA_SITE" "$tmp_target"
    fi
done

# Clear old class map to force regeneration with the site's modules.
rm -f "$QUANTA_DIR/static/tmp/$QUANTA_SITE/class_map.dat"

# Run Quanta doctor to initialize the site.
echo "Running Quanta doctor to initialize site '$QUANTA_SITE'..."
cd "$QUANTA_DIR"
php doctor "$QUANTA_SITE" clear_cache 2>&1 || true
php doctor "$QUANTA_SITE" check 2>&1 || true

# Derived-data dir for locks, trashbin and the metrics arena (the shared-memory
# data segment itself lives on tmpfs, /dev/shm). Pre-create + chown so both qdbd
# and the php-fpm workers (all www-data) can write. It must exist before qdbd
# starts, which it does: supervisord only comes up when this script execs below.
# No index to build here — qdbd loads the whole tree into shared memory on start.
mkdir -p /tmp/quanta_db
chown -R www-data:www-data /tmp/quanta_db 2>/dev/null || true

# Application-specific start-up steps. See "Hooks" in the header.
if [ -d /docker-entrypoint.d ]; then
    for hook in /docker-entrypoint.d/*.sh; do
        [ -e "$hook" ] || continue
        echo "Running entrypoint hook: $hook"
        # shellcheck source=/dev/null
        . "$hook"
    done
fi

# Final ownership pass for the php-fpm workers, after doctor and the hooks have
# had their chance to create files as root. Recursive over the site (db, jobs and
# the rest) and over static (class map, thumbs, tmp files).
chown -R www-data:www-data "$SITE_DIR"
chown -R www-data:www-data "$QUANTA_DIR/static"

# The quanta_db daemon (qdbd) is NOT started here: it is a supervisord program
# (docker/qdbd-run.sh), which honours the QUANTA_DB_DAEMON opt-out
# (off|0|false|no). Starting it here as well would give two daemons writing the
# same shared-memory segment. If it is disabled or dies, the extension detects
# the stale heartbeat and serves from its walk-snapshot fallback automatically,
# so nginx/php-fpm serve regardless.

echo "Quanta site '$QUANTA_SITE' is ready."

# Hand over to the CMD — supervisord, which runs qdbd, php-fpm and nginx.
exec "$@"
