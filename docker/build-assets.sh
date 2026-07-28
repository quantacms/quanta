#!/bin/bash
#
# ── Build the aggregated CSS/JS bundles into the image ────────────────────────
# Installed as /usr/local/bin/quanta-build-assets and run at image *build* time
# (see the Dockerfile), so that no container start has to do it.
#
# The bundles (css.min.css, js.min.js) are aggregated and minified from the
# assets of the loaded modules — image content — yet they used to be rebuilt on
# every boot into static/tmp/<site>/files, which in a Kubernetes deployment is a
# volume every pod shares: each starting pod rewrote the files the running ones
# were serving, and php-fpm inlines css.min.css on every render.
#
# Nothing in the CMS changes here. `doctor <site> check` still aggregates and
# minifies into the site's own tmp dir, exactly where Page::loadIncludes() and
# the /tmp/ alias expect them (so a native installation is unaffected). This
# script only runs that build ahead of time and moves the result out of the
# per-site dir — a volume at runtime, so nothing baked there would survive —
# into QUANTA_ASSETS_DIR. The entrypoint copies them back in on start.
#
# Downstream application images whose own modules add CSS/JS includes
# (hook_load_includes) should re-run this after copying their site in, so those
# assets end up in the bundle too:
#
#     RUN quanta-build-assets
#
# Environment:
#   QUANTA_DIR         Quanta source root       (default /var/www/quanta)
#   QUANTA_SITE        site to build for        (default localhost)
#   QUANTA_ASSETS_DIR  where the bundles are left
#                                (default /usr/local/share/quanta/assets)
set -e

QUANTA_DIR="${QUANTA_DIR:-/var/www/quanta}"
QUANTA_SITE="${QUANTA_SITE:-localhost}"
QUANTA_ASSETS_DIR="${QUANTA_ASSETS_DIR:-/usr/local/share/quanta/assets}"

TMP_FILES="$QUANTA_DIR/static/tmp/$QUANTA_SITE/files"

# doctor boots the CMS for a site name, so the site dir has to exist. In the base
# image there is no site yet (an application image copies one in later); either
# way the bundle depends on the modules' assets, not on the site's content.
mkdir -p "$QUANTA_DIR/sites/$QUANTA_SITE" "$TMP_FILES"

cd "$QUANTA_DIR"
# `check` is the doctor command whose hooks aggregate and minify (page_doctor).
# Its other job, repairing broken symlinks, has nothing to do on a build: there
# is no site data in the image, only code.
php doctor "$QUANTA_SITE" check

mkdir -p "$QUANTA_ASSETS_DIR"
for asset in css.min.css js.min.js; do
    if [ ! -f "$TMP_FILES/$asset" ]; then
        echo "quanta-build-assets: $TMP_FILES/$asset was not generated" >&2
        exit 1
    fi
    mv -f "$TMP_FILES/$asset" "$QUANTA_ASSETS_DIR/$asset"
    chmod 0644 "$QUANTA_ASSETS_DIR/$asset"
done

# Drop what doctor derived under static/ (class map, doctor recipe, the tmp dirs
# themselves): static/ is a volume at runtime, so a baked copy would be hidden
# anyway, and the entrypoint recreates all of it. The site dir is only removed
# when this created it empty — an application image's site must obviously stay.
rm -rf "$QUANTA_DIR/static/tmp/$QUANTA_SITE"
rmdir "$QUANTA_DIR/sites/$QUANTA_SITE" 2>/dev/null || true

echo "quanta-build-assets: bundles built into $QUANTA_ASSETS_DIR"
ls -l "$QUANTA_ASSETS_DIR"
