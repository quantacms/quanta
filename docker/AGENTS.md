# AGENTS.md — Docker image and runtime

Applies to `docker/`, the root `Dockerfile`, `.dockerignore` and the CI workflow. The
repository-wide rules are in `../AGENTS.md`. User-facing behaviour (environment variables,
`/docker-entrypoint.d/`, logging) is documented in `../README.md`. Keep it current when behaviour
changes.

## What the image is

A base image: nginx + php-fpm + `qdbd`, supervised by supervisord. Application images `FROM` it
and add only their site code. The root `Dockerfile` stages, in order:

| Stage | Purpose |
|---|---|
| `qdb-builder` | Compiles `qdb/` (`cargo build --release --locked`). The target-dir cache is keyed by `PHP_VERSION`, so a stale Zend module API can't end up in the `.so` |
| `php-builder` | Adds PHP extensions (gd, zip, calendar, intl) and records their runtime libraries |
| `vendor` | `composer install --no-dev --no-scripts`, prunes Google API services, authoritative class map |
| `squeeze` | Compresses `rclone` |
| `base` | `debian:<suite>-slim` plus the PHP tree, nginx, supervisor and the qdb artifacts. No compiler. |

## Rules

- **The PHP version is an exact pin** (`ARG PHP_VERSION` in `Dockerfile`), and a PHP update is a
  deliberate bump. `qdb/Dockerfile` builds the same minor; move it in step. `ext-php-rs` caps the
  minor (see `qdb/README.md`). `DEBIAN_SUITE` must match the suite of the PHP image.
- **Stay multi-arch clean** (amd64 + arm64, built natively in CI): no architecture-specific
  downloads or binaries.
- **PHP extensions go in `php-builder` only.** The runtime stage has no compiler, so `pecl` and
  `phpize` don't work there.
- **Any build-time `RUN` that starts PHP with the extension must end with `rm -rf /tmp/quanta_db`**,
  otherwise a stale metrics arena is baked into the layer.
- **`.dockerignore` is also the web-root exclusion list.** `COPY . /var/www/quanta/` puts
  everything that survives it under the document root. Exclude new non-runtime files there.
- **`.htaccess` ↔ `docker/nginx/quanta.conf`:** the vhost reproduces the Apache rewrite map one for
  one (the table at the top of `quanta.conf`). Change both, and update that table.
- **Every `location ^~` block re-includes the deny snippets** (`nginx/snippets/deny-dotfiles.conf`,
  `deny-php.conf`, `deny-dev-files.conf`). Prefix matches skip regex locations, so without them the
  denies don't apply there. The `/.well-known/` blocks are the deliberate exceptions: they can't
  deny dotfiles.
- **php-fpm:** `clear_env = no` in `php/zz-quanta.conf` is required so PHP sees `QUANTA_DB_ROOT`.
  The pool files load in order `zz-quanta` < `zzy-autotune` (written at start by
  `php-fpm-autotune.sh`) < a deployment's own `zzz-*`.
- **`/healthz/fpm`:** `nginx -t` can't catch a wrong `fastcgi_param` there. A change is only
  verified by a request that answers `pong`.
- **supervisord:** `qdbd` starts first, then php-fpm, then nginx. Optional programs (the exporter,
  the dashboard) exit 0 when not configured and use `autorestart=unexpected` + `exitcodes=0`, so
  an opt-out doesn't crash-loop. Follow that pattern for new programs.
- **Entrypoint (`docker-entrypoint.sh`):** does the per-site setup, then sources
  `/docker-entrypoint.d/*.sh`, then `exec`s the CMD. Downstream images must not override
  `ENTRYPOINT`. A new ini key whose value depends on the site directory needs its own `sed` next to
  the existing `quanta-db.ini` rewrite. Run doctor there (and in `build-assets.sh`) with
  `QUANTA_DOCTOR_AS_ROOT=1`: those scripts own the directories they just created and fix ownership
  at the end. Replace files with a temp file + rename, never in place:
  `static/` is a volume shared by every container of a site.
- **Comments:** every file here explains why each non-obvious line exists and what broke without
  it. Write new lines the same way, and update the comment when the line changes.

## Test

```sh
sh docker/tests/run-tests.sh            # autotune + dashboard wrapper tests; any host, not run in CI
sh qdb/tests/run-in-docker.sh parity    # builds the full image; also runs nginx -t, php-fpm -t, php -l
DOCKER_BUILDKIT=1 docker build -t quanta-local:php8.5 .   # full build, from the repo root
```

For routing, header or php-fpm changes, also start the image and request the affected URLs
(including `/healthz` and `/healthz/fpm`).
