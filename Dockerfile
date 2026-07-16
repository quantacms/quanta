# syntax=docker/dockerfile:1
#
# ── Quanta CMS base image ─────────────────────────────────────────────────────
# "All dependencies" layer for the Quanta CMS application image. Everything here
# is expensive to build and changes rarely: the native files-DB extension
# (Rust), the PHP extensions, Composer, and the Quanta CMS source + its vendored
# dependencies.
#
# The project's application Dockerfile is meant to `FROM` this image and only
# layer on the fast-changing bits (the site code, the Apache vhost config and
# the entrypoint), so day-to-day app rebuilds skip the Rust compile and the
# apt/composer installs entirely.
#
# Build (context MUST be this quanta/ directory so files-db/ is reachable):
#
#     DOCKER_BUILDKIT=1 docker build -t quanta-cms-base:php8.2 quanta/
#
# Built against php:8.2-apache so the compiled .so matches the production ABI
# (non-thread-safe). Bump PHP_VERSION here and the app image inherits it.

ARG PHP_VERSION=8.2

# ── Stage 1: qdb-builder — compile the native files-DB extension (Rust) ───────
# Kept in the same base image FROM so the cdylib links against the exact PHP ABI
# the runtime uses. build-essential/clang/pkg-config are only needed here and
# never reach the final image.
FROM php:${PHP_VERSION}-apache AS qdb-builder

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

# ── Stage 2: base — PHP + Apache + Quanta CMS with all runtime dependencies ───
FROM php:${PHP_VERSION}-apache AS base

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
    && docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd curl zip calendar intl \
    && a2enmod rewrite headers expires \
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
# applies the extension's write notifications over a unix socket. The entrypoint
# starts it in the background unless QUANTA_DB_DAEMON is off; if it isn't running
# the extension serves from its walk-snapshot fallback automatically.
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

# Set document root to the Quanta root. The app image adds the Quanta vhost
# config (quanta.conf) and the JSON access-log format on top.
ENV APACHE_DOCUMENT_ROOT=/var/www/quanta
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Copy the Quanta CMS source and install its (production) dependencies. The
# Rust source (files-db/) is dropped afterwards — only the compiled .so/daemon
# installed above are needed at runtime. --no-scripts skips the git-hook wiring
# in composer.json (pointless in an image); the composer download cache is a
# BuildKit cache mount.
COPY . /var/www/quanta/
RUN --mount=type=cache,target=/composer/cache \
    cd /var/www/quanta \
    && composer install --no-interaction --no-dev --no-scripts --optimize-autoloader \
    && rm -rf /var/www/quanta/files-db

# Create sites and static directories (gitignored in the Quanta repo).
RUN mkdir -p /var/www/quanta/sites /var/www/quanta/static/tmp

WORKDIR /var/www/quanta

EXPOSE 80
