# syntax=docker/dockerfile:1
#
# ── Quanta CMS base image ─────────────────────────────────────────────────────
# "All dependencies" layer for the Quanta CMS application image. Everything here
# is expensive to build and changes rarely: the native files-DB extension
# (Rust), the PHP extensions, Composer, and the Quanta CMS source + its vendored
# dependencies.
#
# The project's application Dockerfile is meant to `FROM` this image and only
# layer on the fast-changing bits (the site code), so day-to-day app rebuilds
# skip the Rust compile and the apt/composer installs entirely.
#
# The web tier is nginx + php-fpm, supervised by supervisord (docker/). NOTE for
# downstream app images, which previously layered Apache bits and their own
# entrypoint on top:
#   * the vhost now ships here (docker/nginx/quanta.conf) — no Apache
#     quanta.conf or JSON LogFormat to copy, and no .htaccess is read;
#   * qdbd is a supervisord program — do not also start it from an entrypoint;
#   * do not override CMD with apache2-foreground;
#   * the container entrypoint ships here too (docker/docker-entrypoint.sh) and
#     does the whole site setup — writable dirs, host aliases, ownership,
#     doctor, the quanta_db kill switch. Do not replace it: add app-specific
#     start-up steps as a *.sh in /docker-entrypoint.d/, which it sources;
#   * an interactive `exec` into the container lands in a www-data shell (`root`
#     switches back) — the processes themselves still run as root.
#
# Build (context MUST be this quanta/ directory so files-db/ is reachable):
#
#     DOCKER_BUILDKIT=1 docker build -t quanta-cms-base:php8.2 quanta/
#
# Built against php:8.2-fpm so the compiled .so matches the production ABI
# (non-thread-safe). Bump PHP_VERSION here and the app image inherits it.

ARG PHP_VERSION=8.2

# ── Stage 1: qdb-builder — compile the native files-DB extension (Rust) ───────
# Kept in the same base image FROM so the cdylib links against the exact PHP ABI
# the runtime uses. build-essential/clang/pkg-config are only needed here and
# never reach the final image.
FROM php:${PHP_VERSION}-fpm AS qdb-builder

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl build-essential clang libclang-dev pkg-config git \
    && rm -rf /var/lib/apt/lists/*

ENV RUSTUP_HOME=/opt/rustup CARGO_HOME=/opt/cargo PATH=/opt/cargo/bin:$PATH
RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs \
    | sh -s -- -y --profile minimal --default-toolchain stable

WORKDIR /qdb
# Copy only the crate manifest + sources (see .dockerignore: target/ is excluded)
# so the cargo layer caches on source changes alone.
COPY files-db/Cargo.toml files-db/Cargo.lock ./
COPY files-db/src ./src

# --locked builds exactly the pinned Cargo.lock; LTO + codegen-units=1 come from
# the crate's [profile.release]. The registry and target dirs are BuildKit cache
# mounts, so incremental rebuilds reuse compiled deps. Symbols are stripped from
# the artifacts to keep the runtime image small.
RUN --mount=type=cache,target=/opt/cargo/registry \
    --mount=type=cache,target=/qdb/target \
    cargo build --release --locked \
    && install -D target/release/libquanta_db.so /out/quanta_db.so \
    && install -D target/release/qdbstat        /out/qdbstat \
    && install -D target/release/qdbd           /out/qdbd \
    && strip /out/quanta_db.so /out/qdbstat /out/qdbd

# ── Stage 2: base — nginx + php-fpm + Quanta CMS with all runtime deps ────────
FROM php:${PHP_VERSION}-fpm AS base

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/composer

# System libraries + PHP extensions + tools. Ordered first because it is the
# slowest-changing layer. The apt archive dir is a BuildKit cache mount (with
# docker-clean removed so .debs persist), and the extension build tooling that
# ships in the php image is reused — no build-essential needed here.
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    rm -f /etc/apt/apt.conf.d/docker-clean \
    && apt-get update && apt-get install -y --no-install-recommends \
        libgd-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libwebp-dev \
        libcurl4-openssl-dev \
        libzip-dev \
        libicu-dev \
        unzip \
        rclone \
        nginx \
        supervisor \
        gosu \
    && docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd curl zip calendar intl \
    && rm -rf /var/lib/apt/lists/*

# quanta_db: fast, concurrency-safe access to the files DB. Root is the
# canonical site dir (host aliases are symlinks to it). The qdbd daemon holds
# the whole tree in a shared-memory segment (tmpfs, /dev/shm) that the
# extension maps read-only; locks and trashbin default under /tmp/quanta_db —
# all derived, per-pod, and rebuildable at any time with QuantaDb::reindex()
# (files stay the source of truth). The PHP shims (and the extension's own
# fallback mode) degrade to legacy behavior if the daemon is down or this ini
# is removed.
COPY --from=qdb-builder /out/quanta_db.so /usr/local/lib/php/extensions/quanta_db.so
RUN { \
    echo 'extension=/usr/local/lib/php/extensions/quanta_db.so'; \
    echo 'quanta_db.root=/var/www/quanta/sites/localhost'; \
    } > /usr/local/etc/php/conf.d/quanta-db.ini

# qdbstat: varnishstat-style live monitor. Reads the per-pod shared-memory
# counter arena + active data segment. QUANTA_DB_ROOT lets it (and the PHP env
# fallback) resolve the same derived-data dir the php.ini root points at, so
# `kubectl exec <pod> -- qdbstat --once` works with no arguments.
COPY --from=qdb-builder /out/qdbstat /usr/local/bin/qdbstat

# qdbd: the files-db daemon. Loads the whole node tree into a shared-memory
# segment at boot, keeps it authoritative via inotify + periodic reconcile, and
# applies the extension's write notifications over a unix socket. supervisord
# starts it (via docker/qdbd-run.sh) unless QUANTA_DB_DAEMON is off; if it isn't
# running the extension serves from its walk-snapshot fallback automatically.
COPY --from=qdb-builder /out/qdbd /usr/local/bin/qdbd
ENV QUANTA_DB_ROOT=/var/www/quanta/sites/localhost

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Send PHP errors to stderr so they appear in container logs (Grafana/Loki)
RUN { \
    echo 'log_errors = On'; \
    echo 'error_log = /dev/stderr'; \
    echo 'error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT'; \
    } > /usr/local/etc/php/conf.d/logging.ini

# Store PHP sessions in an explicit path. The base php image leaves
# session.save_path unset (sessions land in /tmp, lost per-pod). When running
# multiple pods this path is backed by a shared hostPath volume so every pod
# reads and writes the same sessions (see Helm chart: hostData php-sessions).
RUN { \
    echo 'session.save_handler = files'; \
    echo 'session.save_path = "/var/lib/php/sessions"'; \
    } > /usr/local/etc/php/conf.d/sessions.ini \
    && mkdir -p /var/lib/php/sessions

# Web tier: nginx (vhost + cache zones) in front of php-fpm over a unix socket,
# both supervised by supervisord. quanta.conf reproduces the .htaccess rewrite
# map and adds the fastcgi caches for node media and anonymous HTML; the
# document root (/var/www/quanta) is set there, not via an env var.
COPY docker/nginx/nginx.conf          /etc/nginx/nginx.conf
COPY docker/nginx/quanta.conf         /etc/nginx/conf.d/default.conf
COPY docker/nginx/snippets/           /etc/nginx/snippets/
COPY docker/php/zz-quanta.conf        /usr/local/etc/php-fpm.d/zz-quanta.conf
COPY docker/supervisord.conf          /etc/supervisor/conf.d/quanta.conf
COPY docker/qdbd-run.sh               /usr/local/bin/qdbd-run.sh
COPY docker/build-assets.sh           /usr/local/bin/quanta-build-assets

# Container entrypoint: the site-agnostic first-boot setup (writable dirs, host
# aliases, ownership, doctor, the quanta_db kill switch) that every Quanta
# container needs before supervisord starts. Downstream application images
# should NOT replace it — they add their own start-up steps by copying a *.sh
# into /docker-entrypoint.d/, which the entrypoint sources before the exec.
COPY docker/docker-entrypoint.sh      /usr/local/bin/docker-entrypoint.sh

# Interactive shells run as www-data. The container's processes stay root (nginx
# binds :80), but `docker exec -it <c> bash` / `kubectl exec -it <pod> -- bash`
# lands in a www-data shell — so a doctor/composer run typed by hand cannot
# leave root-owned files in the site volume that the php-fpm workers can no
# longer write. It is a child of the root shell the exec started, so `root`
# (or Ctrl-D) gets root back; see docker/shell.bashrc for the whole story. Both
# hooks are guarded, so non-interactive execs and scripts are unaffected.
COPY docker/shell.bashrc              /etc/quanta/shell.bashrc
RUN printf '%s\n' \
        '' \
        '# Quanta: interactive shells run as www-data (see the file).' \
        '[ -r /etc/quanta/shell.bashrc ] && . /etc/quanta/shell.bashrc' \
        >> /root/.bashrc \
    && printf '%s\n' \
        '# Quanta: same treatment for login shells (bash -l, su -).' \
        '[ -r /etc/quanta/shell.bashrc ] && . /etc/quanta/shell.bashrc' \
        > /etc/profile.d/00-quanta-shell.sh

# Drop Debian's stock vhost (it would shadow ours on the default server) and
# create the cache/runtime dirs nginx and php-fpm write to.
RUN rm -f /etc/nginx/sites-enabled/default /etc/nginx/conf.d/default.conf.dpkg-dist \
    && chmod +x /usr/local/bin/qdbd-run.sh /usr/local/bin/docker-entrypoint.sh \
                /usr/local/bin/quanta-build-assets \
    && mkdir -p /docker-entrypoint.d \
    && mkdir -p /var/cache/nginx/assets /var/cache/nginx/html /var/lib/nginx /run \
    && chown -R www-data:www-data /var/cache/nginx /var/lib/nginx /var/lib/php/sessions

# Copy the Quanta CMS source and install its (production) dependencies. The
# Rust source (files-db/) and the server configs (docker/, already installed
# under /etc above) are dropped afterwards — neither belongs under the web
# root. --no-scripts skips the git-hook wiring in composer.json (pointless in
# an image); the composer download cache is a BuildKit cache mount.
COPY . /var/www/quanta/
RUN --mount=type=cache,target=/composer/cache \
    cd /var/www/quanta \
    && composer install --no-interaction --no-dev --no-scripts --optimize-autoloader \
    && rm -rf /var/www/quanta/files-db /var/www/quanta/docker

# Create sites and static directories (gitignored in the Quanta repo). php-fpm
# runs as www-data and writes derived data here (class map, thumbs, tmp files),
# so the tree must be owned by it.
RUN mkdir -p /var/www/quanta/sites /var/www/quanta/static/tmp \
    && chown -R www-data:www-data /var/www/quanta/sites /var/www/quanta/static

# Aggregate + minify the modules' CSS/JS now, instead of on every container
# start. quanta-build-assets runs the CMS's own `doctor <site> check` and leaves
# css.min.css / js.min.js in QUANTA_ASSETS_DIR; the entrypoint copies them into
# the site's tmp dir at start, which is where the CMS reads them from (nothing in
# the CMS changes — a native installation still builds them with doctor).
#
# NOTE for downstream application images: if your own modules add CSS/JS includes
# (hook_load_includes), add `RUN quanta-build-assets` after copying your site in,
# so those assets are in the bundle.
ENV QUANTA_ASSETS_DIR=/usr/local/share/quanta/assets
RUN quanta-build-assets

WORKDIR /var/www/quanta

EXPOSE 80

# The entrypoint does the site setup and then `exec "$@"`s into the CMD.
# Downstream images inherit both — do not restate ENTRYPOINT there, as that
# resets this CMD to null and would need supervisord repeated.
ENTRYPOINT ["docker-entrypoint.sh"]

# Replaces the php:*-apache image's apache2-foreground. supervisord runs qdbd,
# php-fpm and nginx; see docker/supervisord.conf.
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
