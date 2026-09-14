# syntax=docker/dockerfile:1
#
# ── Quanta CMS base image ─────────────────────────────────────────────────────
# "All dependencies" layer for the Quanta CMS application image. Everything here
# is expensive to build and changes rarely: the native qdb extension
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
# Build (context MUST be this quanta/ directory so qdb/ is reachable):
#
#     DOCKER_BUILDKIT=1 docker build -t quanta-cms-base:php8.5 quanta/
#
# Built against php:8.5-fpm so the compiled .so matches the production ABI
# (non-thread-safe). Bump PHP_VERSION here and the app image inherits it.
#
# 8.5 is also the ceiling of ext-php-rs 0.15 (qdb/Cargo.toml): its build
# script rejects any PHP whose Zend API is newer than 20250925, so PHP 8.6 needs
# a crate upgrade first, not just a bump here.
#
# ── Image layout ──────────────────────────────────────────────────────────────
# The runtime stage is NOT php:${PHP_VERSION}-fpm. That image carries ~370 MB of
# C toolchain (PHPIZE_DEPS: gcc, g++, binutils, libc-dev, re2c, perl …) that
# exists only so `docker-php-ext-install` and `pecl` can compile — none of it is
# reachable at runtime, and shipping a compiler in a web-facing image is a
# liability, not a feature. So the toolchain-bearing image is a *builder*: it
# compiles the PHP extensions, then the runtime stage starts from the very same
# debian-slim the php image itself is built on and takes only /usr/local (the
# PHP install) plus the shared libraries it actually links against.
#
# Consequences, in exchange for roughly a third of the previous size:
#   * there is no compiler in the runtime image, so `pecl install` / `phpize`
#     do not work there. Add extensions in the php-builder stage instead (that
#     is where docker-php-ext-install belongs anyway), or in a downstream image
#     that builds them against php:${PHP_VERSION}-fpm and COPYs the .so in.
#     `docker-php-ext-enable` still works — it only writes an ini file;
#   * the PHP headers (/usr/local/include/php) are dropped for the same reason;
#   * DEBIAN_SUITE below must match the suite php:${PHP_VERSION}-fpm is built
#     on. The build asserts this rather than producing a subtly broken image.

ARG PHP_VERSION=8.5

# The Debian release the runtime stage starts from. It MUST be the one
# php:${PHP_VERSION}-fpm is built on — /usr/local is copied wholesale out of
# that image, so glibc and every shared library it links have to be the same
# vintage. The final stage asserts it and tells you the right value if it is
# not; there is no need to guess.
ARG DEBIAN_SUITE=trixie

# ── Stage 1: qdb-builder — compile the native qdb extension (Rust) ───────
# Kept in the same base image FROM so the cdylib links against the exact PHP ABI
# the runtime uses. build-essential/clang/pkg-config are only needed here and
# never reach the final image.
FROM php:${PHP_VERSION}-fpm AS qdb-builder

# Re-declared so the cache-mount id below can interpolate it (a global ARG is
# only visible to FROM lines until a stage opts back in).
ARG PHP_VERSION

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl build-essential clang libclang-dev pkg-config git \
    && rm -rf /var/lib/apt/lists/*

ENV RUSTUP_HOME=/opt/rustup CARGO_HOME=/opt/cargo PATH=/opt/cargo/bin:$PATH
RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs \
    | sh -s -- -y --profile minimal --default-toolchain stable

WORKDIR /qdb
# Copy only the crate manifest + sources (see .dockerignore: target/ is excluded)
# so the cargo layer caches on source changes alone.
COPY qdb/Cargo.toml qdb/Cargo.lock ./
COPY qdb/src ./src

# --locked builds exactly the pinned Cargo.lock; LTO + codegen-units=1 come from
# the crate's [profile.release]. The registry and target dirs are BuildKit cache
# mounts, so incremental rebuilds reuse compiled deps. Symbols are stripped from
# the artifacts to keep the runtime image small.
#
# The target dir cache is keyed by PHP_VERSION, and that is load-bearing.
# ext-php-rs's build script declares rerun-if-changed on its own sources and
# rerun-if-env-changed on PHP/PHP_CONFIG/PATH — but NOT on the PHP headers it
# generates bindings from. Across a PHP bump every one of those inputs is
# byte-identical, so cargo considers the cached build script fresh and relinks
# the previous version's bindings. The result is a .so stamped with the OLD
# ZEND_MODULE_API_NO, which the new PHP then refuses to load ("Module compiled
# with module API=..."). Separate cache namespaces make that impossible.
RUN --mount=type=cache,target=/opt/cargo/registry \
    --mount=type=cache,target=/qdb/target,id=qdb-target-${PHP_VERSION} \
    cargo build --release --locked \
    && install -D target/release/libquanta_db.so /out/quanta_db.so \
    && install -D target/release/qdbstat        /out/qdbstat \
    && install -D target/release/qdbd           /out/qdbd \
    && strip /out/quanta_db.so /out/qdbstat /out/qdbd

# ── Stage 2: php-builder — the PHP extensions, and nothing else ───────────────
# Everything the *dev* packages exist for happens here: only the built .so files
# and the runtime .so's they link against move on to the runtime stage. The
# -dev packages themselves (libicu-dev alone unpacks ~50 MB of static archives)
# never leave this stage.
FROM php:${PHP_VERSION}-fpm AS php-builder

# Two things are deliberately absent from this list.
#
# libgd-dev: `docker-php-ext-configure gd` without --with-external-gd builds
# PHP's *bundled* libgd, so the system one was never linked — it only dragged
# libavif/libaom/libsvtav1enc (~25 MB of runtime libs, for an AVIF codepath the
# bundled GD does not even expose) into the image. libpng-dev is listed
# explicitly because it used to arrive only as a libgd-dev dependency, and
# losing PNG support out of gd would have been a silent regression.
#
# libcurl4-openssl-dev: php:${PHP_VERSION}-fpm is configured --with-curl, so
# ext/curl is already built into the binary. `docker-php-ext-install curl`
# compiled a second, shared copy that docker-php-ext-enable then declined to
# enable (there is no docker-php-ext-curl.ini in the image) — a dead .so and a
# dev package for an extension that was never not there. libcurl itself stays:
# the ldd scan below still finds it behind the built-in.
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    set -eux; \
    rm -f /etc/apt/apt.conf.d/docker-clean; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        libicu-dev \
    ; \
    docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp; \
    docker-php-ext-install -j"$(nproc)" gd zip calendar intl; \
    rm -rf /var/lib/apt/lists/*

# Build-only payload inside /usr/local, which is copied wholesale below: the PHP
# headers and the phpize build system. Both are only useful with a compiler, and
# the runtime stage has none. (/usr/src/php.tar.xz — another 14 MB — needs no
# handling: it is outside /usr/local and so is simply never copied.)
RUN rm -rf /usr/local/include/php /usr/local/lib/php/build

# The exact set of Debian packages that own the shared libraries /usr/local
# links against — php-fpm, the php CLI and every extension .so. Resolving it by
# ldd rather than by hand means a PHP bump, a new extension or a Debian package
# rename cannot leave the runtime stage missing a library (and cannot leave it
# carrying one it no longer needs). This is the same scan the official php image
# uses to decide what to keep when it purges its own build deps.
#
# The suite codename travels with it so the runtime stage can assert it starts
# from the matching debian-slim.
RUN set -eux; \
    mkdir -p /out; \
    find /usr/local -type f -executable -exec ldd '{}' ';' 2>/dev/null \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
        | sort -u \
        | xargs -r dpkg-query --search 2>/dev/null \
        | awk 'sub(":$", "", $1) { print $1 }' \
        | sort -u > /out/runtime-deps.txt; \
    . /etc/os-release; printf '%s\n' "$VERSION_CODENAME" > /out/debian-suite; \
    echo "runtime library packages:"; cat /out/runtime-deps.txt

# ── Stage 3: vendor — Composer dependencies, pruned ───────────────────────────
# FROM php-builder, not the stock php image, so Composer resolves against the
# exact extension set production has: a platform requirement that would fail in
# the real image fails here, at build time, instead of at runtime.
FROM php-builder AS vendor

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/composer

# google/apiclient-services ships every Google API Google has ever published:
# 332 services, 210 MB, of which this codebase uses three (Drive, Docs, Oauth2 —
# see src/modules/googledrive and src/modules/googledocs). Keeping the rest is
# most of the vendor tree, most of the Composer classmap, and a permanent tax on
# every image pull.
#
# This is the same prune Google's own `Google\Task\Composer::cleanup` script
# performs from composer.json's `extra` — done here because the install below
# runs --no-scripts. A downstream image needing another API rebuilds this base
# with the service added:
#
#     docker build --build-arg GOOGLE_API_SERVICES="Docs Drive Oauth2 Sheets" .
#
# Set it to "*" to keep the lot.
ARG GOOGLE_API_SERVICES="Docs Drive Oauth2"

RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json ./

# --no-autoloader: the autoloader is dumped *after* the prune below, so the
# classmap describes what actually ships. Dumping first would bake 300+ dead
# service classes into it (13 MB of classmap alone) and leave every one of them
# pointing at a deleted file.
RUN --mount=type=cache,target=/composer/cache \
    composer install --no-interaction --no-dev --no-scripts --no-progress --no-autoloader

RUN set -eux; \
    dir=/app/vendor/google/apiclient-services/src; \
    if [ -d "$dir" ] && [ "$GOOGLE_API_SERVICES" != "*" ]; then \
        before="$(du -sm /app/vendor | cut -f1)"; \
        keep=" $GOOGLE_API_SERVICES "; \
        for path in "$dir"/*; do \
            name="$(basename "$path")"; name="${name%.php}"; \
            case "$keep" in *" $name "*) continue ;; esac; \
            rm -rf "$path"; \
        done; \
        echo "apiclient-services: kept [$GOOGLE_API_SERVICES]; vendor ${before}M -> $(du -sm /app/vendor | cut -f1)M"; \
    fi

# --classmap-authoritative rather than --optimize-autoloader: in an image whose
# PHP is immutable (opcache.validate_timestamps=0 already assumes it) there is
# nothing for the PSR-4 filesystem fallback to find, so every miss it saves is a
# stat() saved on a hot path. The legacy Google_Service_* names still resolve —
# google/apiclient registers its own alias autoloader, which PHP tries after
# Composer's declines.
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && du -sh /app/vendor

# ── Stage 4: squeeze — UPX-compress rclone ────────────────────────────────────
# rclone is a 54 MB static Go binary and the single largest file in the runtime
# image after PHP itself — larger than nginx, supervisor and every PHP extension
# put together. It is also a batch tool: it runs occasionally, never in the
# request path, and a run that does any real work lasts far longer than its own
# startup. That makes it the one binary here worth trading startup time for
# size, and the only one that is compressed.
#
# Measured on this image, ten `rclone version` invocations each:
#
#     uncompressed        54.2 MB    23 ms
#     upx -9              18.5 MB   129 ms   <- what we ship
#     upx --best --lzma   13.4 MB   546 ms
#
# -9 is the knee of that curve: 88% of the saving for 20% of the cost. LZMA's
# extra 5 MB is not worth half a second on every invocation, in case anything
# downstream ever calls rclone per-file rather than per-job.
#
# Nothing else is packed, and that is deliberate: qdbd, php-fpm and nginx are
# resident processes whose text pages are file-backed, shared between forks and
# demand-paged. Packing them would trade a one-off saving on disk for higher
# RSS in every pod, for the lifetime of the pod — the opposite of the trade
# above.
#
# Note UPX-packed executables are flagged by some malware scanners. Build with
# --build-arg RCLONE_UPX=0 to ship the binary exactly as Debian packages it.
FROM debian:${DEBIAN_SUITE}-slim AS squeeze
ARG RCLONE_UPX=1
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    set -eux; \
    rm -f /etc/apt/apt.conf.d/docker-clean; \
    apt-get update; \
    apt-get install -y --no-install-recommends rclone $([ "$RCLONE_UPX" = "1" ] && echo upx-ucl); \
    mkdir -p /out; \
    if [ "$RCLONE_UPX" = "1" ]; then \
        upx -9 -o /out/rclone /usr/bin/rclone; \
    else \
        cp /usr/bin/rclone /out/rclone; \
    fi; \
    /out/rclone version | head -1; \
    rm -rf /var/lib/apt/lists/*

# ── Stage 5: base — nginx + php-fpm + Quanta CMS with all runtime deps ────────
# Starts from the same debian-slim php:${PHP_VERSION}-fpm is built on and takes
# PHP from the builder, so the C toolchain, the -dev packages and the PHP
# sources never exist here. See "Image layout" at the top for what that costs.
FROM debian:${DEBIAN_SUITE}-slim AS base

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/composer \
    PHP_INI_DIR=/usr/local/etc/php

# The whole PHP install: binaries, extensions, php.ini-*, the php-fpm pool
# configs. --link so this 66 MB layer is content-addressed independently of the
# base below it — a Debian base bump then re-uses it instead of rebuilding it.
COPY --link --from=php-builder /usr/local /usr/local
COPY --from=php-builder /out/runtime-deps.txt /out/debian-suite /tmp/

# Runtime packages only:
#   * the shared libraries /usr/local links against, computed by ldd upstream;
#   * the web tier (nginx, supervisor) and the tools the image's own scripts
#     need — gosu for docker/shell.bashrc, unzip so a hand-run `composer` in
#     the container can still extract dists, ca-certificates because PHP's curl
#     and openssl are useless without a trust store (and debian-slim ships none).
#
# The suite assertion first: a mismatch here produces an image whose PHP dies on
# a missing GLIBC symbol at runtime, which is a far worse way to find out.
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    set -eux; \
    . /etc/os-release; \
    want="$(cat /tmp/debian-suite)"; \
    if [ "$VERSION_CODENAME" != "$want" ]; then \
        echo "ERROR: runtime base is debian:$VERSION_CODENAME but php-fpm was built on $want." >&2; \
        echo "       Rebuild with --build-arg DEBIAN_SUITE=$want" >&2; \
        exit 1; \
    fi; \
    rm -f /etc/apt/apt.conf.d/docker-clean; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        $(cat /tmp/runtime-deps.txt) \
        ca-certificates \
        nginx \
        supervisor \
        gosu \
        unzip \
    ; \
    rm -rf /var/lib/apt/lists/* /tmp/runtime-deps.txt /tmp/debian-suite

COPY --link --from=squeeze /out/rclone /usr/local/bin/rclone

# quanta_db: fast, concurrency-safe access to the qdb index. Root is the
# canonical site dir (host aliases are symlinks to it). The qdbd daemon holds
# the whole tree in a shared-memory segment (tmpfs, /dev/shm) that the
# extension maps read-only; locks default under /tmp/quanta_db — all derived,
# per-pod, and rebuildable at any time with QuantaDb::reindex() (files stay the
# source of truth). The PHP shims (and the extension's own fallback mode)
# degrade to legacy behavior if the daemon is down or this ini is removed.
#
# trashbin_dir is the exception to "derived data lives under /tmp": it is pointed
# at the location Quanta's own Node::delete() uses (Environment->dir['trashbin'])
# so a site adopting QuantaDb::delete() finds deleted nodes where it already
# expects them, not on pod-local storage. Re-pointed for a non-default
# QUANTA_SITE by docker-entrypoint.sh.
COPY --link --from=qdb-builder /out/quanta_db.so /usr/local/lib/php/extensions/quanta_db.so

# qdbstat: varnishstat-style live monitor. Reads the per-pod shared-memory
# counter arena + active data segment. QUANTA_DB_ROOT lets it (and the PHP env
# fallback) resolve the same derived-data dir the php.ini root points at, so
# `kubectl exec <pod> -- qdbstat --once` works with no arguments. The same
# binary serves those counters as Prometheus /metrics with --listen; supervisord
# runs it that way when QUANTA_DB_METRICS_LISTEN is set (docker/qdbstat-exporter.sh).
#
# It also serves a read-only web browser for the indexed files with --dashboard,
# started by supervisord when QUANTA_DB_DASHBOARD_LISTEN is set
# (docker/qdbstat-dashboard.sh). That one carries node paths and document
# content rather than counters, so it binds loopback unless told otherwise and
# takes an optional QUANTA_DB_DASHBOARD_TOKEN. Neither port is EXPOSEd: this
# image is a base for deployments that never asked to open one.
#
# qdbd: the qdb daemon. Loads the whole node tree into a shared-memory
# segment at boot, keeps it authoritative via inotify + periodic reconcile, and
# applies the extension's write notifications over a unix socket. supervisord
# starts it (via docker/qdbd-run.sh) unless QUANTA_DB_DAEMON is off; if it isn't
# running the extension serves from its walk-snapshot fallback automatically.
COPY --link --from=qdb-builder /out/qdbstat /out/qdbd /usr/local/bin/
ENV QUANTA_DB_ROOT=/var/www/quanta/sites/localhost

# Install Composer
COPY --link --from=composer:2 /usr/bin/composer /usr/bin/composer

# The PHP ini fragments, in one layer: the extension wiring, error logging to
# stderr (so Grafana/Loki see it), an explicit session path, and opcache.
#
#   session.save_path -- the base php image leaves it unset, so sessions land in
#     /tmp and are lost per-pod. With multiple pods this path is a shared
#     hostPath volume (see Helm chart: hostData php-sessions).
#   opcache.validate_timestamps=0 -- all PHP here is immutable (only db/,
#     static/, jobs/ and sessions are mounts). Editing PHP in a running pod now
#     needs a php-fpm reload: kill -USR2 <master>.
#   opcache.interned_strings_buffer=32 -- the 8 MB default measured 100% full at
#     58k strings, so PHP had stopped interning. Costs ~24 MB shared memory.
RUN set -eux; \
    { \
        echo 'extension=/usr/local/lib/php/extensions/quanta_db.so'; \
        echo 'quanta_db.root=/var/www/quanta/sites/localhost'; \
        echo 'quanta_db.trashbin_dir=/var/www/quanta/static/tmp/localhost/trashbin'; \
    } > "$PHP_INI_DIR/conf.d/quanta-db.ini"; \
    { \
        echo 'log_errors = On'; \
        echo 'error_log = /dev/stderr'; \
        echo 'error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT'; \
    } > "$PHP_INI_DIR/conf.d/logging.ini"; \
    { \
        echo 'session.save_handler = files'; \
        echo 'session.save_path = "/var/lib/php/sessions"'; \
    } > "$PHP_INI_DIR/conf.d/sessions.ini"; \
    { \
        echo 'opcache.validate_timestamps = 0'; \
        echo 'opcache.interned_strings_buffer = 32'; \
    } > "$PHP_INI_DIR/conf.d/opcache.ini"; \
    mkdir -p /var/lib/php/sessions

# Nothing above may have left a dangling shared-library reference: the runtime
# package list was derived from /usr/local alone, so the Rust artifacts copied
# in after it are the case that could slip through. Fail the build here rather
# than ship an image whose php-fpm cannot start.
RUN set -eux; \
    missing="$( \
        ldd /usr/local/sbin/php-fpm /usr/local/bin/php /usr/local/bin/qdbd \
            /usr/local/bin/qdbstat /usr/local/bin/rclone \
            /usr/local/lib/php/extensions/quanta_db.so \
            /usr/local/lib/php/extensions/*/*.so 2>/dev/null \
        | grep 'not found' || true)"; \
    [ -z "$missing" ] || { echo "ERROR: unresolved shared libraries:"; echo "$missing"; exit 1; }; \
    php --version; \
    php -m

# Web tier: nginx (vhost + cache zones) in front of php-fpm over a unix socket,
# both supervised by supervisord. quanta.conf reproduces the .htaccess rewrite
# map and adds the fastcgi caches for node media and anonymous HTML; the
# document root (/var/www/quanta) is set there, not via an env var.
COPY --link docker/nginx/nginx.conf          /etc/nginx/nginx.conf
COPY --link docker/nginx/quanta.conf         /etc/nginx/conf.d/default.conf
COPY --link docker/nginx/snippets/           /etc/nginx/snippets/
COPY --link docker/php/zz-quanta.conf        /usr/local/etc/php-fpm.d/zz-quanta.conf
COPY --link docker/supervisord.conf          /etc/supervisor/conf.d/quanta.conf
COPY --link docker/qdbd-run.sh               /usr/local/bin/qdbd-run.sh
COPY --link docker/qdbstat-exporter.sh       /usr/local/bin/qdbstat-exporter.sh
COPY --link docker/qdbstat-dashboard.sh      /usr/local/bin/qdbstat-dashboard.sh
COPY --link docker/build-assets.sh           /usr/local/bin/quanta-build-assets

# Pool sizing derived from the container's own cgroup limits, run by the
# entrypoint into php-fpm.d/zzy-autotune.conf. It sorts after zz-quanta.conf
# (the image's defaults) and before any zzz-*.conf a deployment renders, so it
# raises the floor without taking the override away.
COPY --link docker/php-fpm-autotune.sh       /usr/local/bin/php-fpm-autotune.sh

# Container entrypoint: the site-agnostic first-boot setup (writable dirs, host
# aliases, ownership, doctor, the quanta_db kill switch) that every Quanta
# container needs before supervisord starts. Downstream application images
# should NOT replace it — they add their own start-up steps by copying a *.sh
# into /docker-entrypoint.d/, which the entrypoint sources before the exec.
COPY --link docker/docker-entrypoint.sh      /usr/local/bin/docker-entrypoint.sh

# Interactive shells run as www-data. The container's processes stay root (nginx
# binds :80), but `docker exec -it <c> bash` / `kubectl exec -it <pod> -- bash`
# lands in a www-data shell — so a doctor/composer run typed by hand cannot
# leave root-owned files in the site volume that the php-fpm workers can no
# longer write. It is a child of the root shell the exec started, so `root`
# (or Ctrl-D) gets root back; see docker/shell.bashrc for the whole story. Both
# hooks are guarded, so non-interactive execs and scripts are unaffected.
COPY --link docker/shell.bashrc              /etc/quanta/shell.bashrc

# Shell hooks, the stock-vhost removal (it would shadow ours on the default
# server) and the cache/runtime dirs nginx and php-fpm write to.
RUN set -eux; \
    printf '%s\n' \
        '' \
        '# Quanta: interactive shells run as www-data (see the file).' \
        '[ -r /etc/quanta/shell.bashrc ] && . /etc/quanta/shell.bashrc' \
        >> /root/.bashrc; \
    printf '%s\n' \
        '# Quanta: same treatment for login shells (bash -l, su -).' \
        '[ -r /etc/quanta/shell.bashrc ] && . /etc/quanta/shell.bashrc' \
        > /etc/profile.d/00-quanta-shell.sh; \
    rm -f /etc/nginx/sites-enabled/default /etc/nginx/conf.d/default.conf.dpkg-dist; \
    chmod +x /usr/local/bin/qdbd-run.sh /usr/local/bin/qdbstat-exporter.sh \
             /usr/local/bin/qdbstat-dashboard.sh \
             /usr/local/bin/docker-entrypoint.sh \
             /usr/local/bin/quanta-build-assets /usr/local/bin/php-fpm-autotune.sh; \
    mkdir -p /docker-entrypoint.d /var/cache/nginx/assets /var/cache/nginx/html \
             /var/lib/nginx /run; \
    chown -R www-data:www-data /var/cache/nginx /var/lib/nginx /var/lib/php/sessions

# The vendored dependencies, built and pruned in the vendor stage. Copied ahead
# of the source so a code-only change does not re-do Composer's work, and as
# their own layer so an image pull that already has them skips them.
COPY --link --from=vendor /app/vendor /var/www/quanta/vendor

# The Quanta CMS source. The Rust source (qdb/) and the server configs
# (docker/, already installed under /etc above) are dropped afterwards — neither
# belongs under the web root.
COPY . /var/www/quanta/
RUN rm -rf /var/www/quanta/qdb /var/www/quanta/docker

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
# The doctor run loads the quanta_db extension, which creates the per-pod
# metrics arena under /tmp/quanta_db. Left in place it is baked into the image
# layer, and every container then starts with a stale arena in a *lower*
# overlayfs layer: the first writer's O_RDWR open copies the file up and writes
# to the upper copy, while any reader that opened the lower one keeps a frozen
# view of it -- with the same st_ino, because overlayfs deliberately keeps
# inode numbers stable across a copy-up, so nothing about the path says the
# contents diverged. That stranded `qdbstat --listen` on all-zero counters for a
# pod's whole life. The arena is derived, per-pod state; it has no business in
# an image layer at all, and the entrypoint recreates the directory anyway.
RUN quanta-build-assets \
    && rm -rf /tmp/quanta_db

WORKDIR /var/www/quanta

EXPOSE 80

# The entrypoint does the site setup and then `exec "$@"`s into the CMD.
# Downstream images inherit both — do not restate ENTRYPOINT there, as that
# resets this CMD to null and would need supervisord repeated.
ENTRYPOINT ["docker-entrypoint.sh"]

# Replaces the php:*-apache image's apache2-foreground. supervisord runs qdbd,
# php-fpm and nginx; see docker/supervisord.conf.
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
