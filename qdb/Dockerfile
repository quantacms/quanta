# syntax=docker/dockerfile:1
# Build + conformance-test image for the quanta_db PHP extension.
# Built against php:8.5-fpm so the .so matches the production ABI (NTS).
#
#   docker build -t quanta-db quanta/qdb/            # builds ext AND runs tests
#   docker build --target artifact -o dist quanta/qdb/   # extract quanta_db.so
#
# PHP_VERSION mirrors the root Dockerfile's ARG on purpose — the .so is only
# loadable by the exact PHP minor it was compiled against, so the two must not
# drift. Keep them in step, or override both:
#
#   docker build --build-arg PHP_VERSION=8.4 -t quanta-db quanta/qdb/

ARG PHP_VERSION=8.5

FROM php:${PHP_VERSION}-fpm AS builder-base
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl build-essential clang libclang-dev pkg-config git \
    && rm -rf /var/lib/apt/lists/*
ENV RUSTUP_HOME=/opt/rustup CARGO_HOME=/opt/cargo PATH=/opt/cargo/bin:$PATH
RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs \
    | sh -s -- -y --profile minimal --default-toolchain stable

FROM builder-base AS builder
# Re-declared so the cache-mount id below can interpolate it (a global ARG is
# only visible to FROM lines until a stage opts back in).
ARG PHP_VERSION
WORKDIR /ext
COPY Cargo.toml ./
COPY src ./src
# The target dir cache is keyed by PHP version, and that is load-bearing.
# ext-php-rs's build script declares rerun-if-changed on its own sources and
# rerun-if-env-changed on PHP/PHP_CONFIG/PATH — but NOT on the PHP headers it
# generates bindings from. Across a PHP bump every one of those inputs is
# byte-identical, so cargo considers the cached build script fresh and relinks
# the previous version's bindings. The result is a .so stamped with the OLD
# ZEND_MODULE_API_NO, which the new PHP then refuses to load ("Module compiled
# with module API=...").  Separate cache namespaces make that impossible.
RUN --mount=type=cache,target=/opt/cargo/registry \
    --mount=type=cache,target=/ext/target,id=qdb-target-${PHP_VERSION} \
    cargo build --release \
    && install -D target/release/libquanta_db.so /out/quanta_db.so \
    && install -D target/release/qdbd /out/qdbd \
    && install -D target/release/qdbstat /out/qdbstat

FROM scratch AS artifact
COPY --from=builder /out/quanta_db.so /quanta_db.so
COPY --from=builder /out/qdbd /qdbd
COPY --from=builder /out/qdbstat /qdbstat

FROM php:${PHP_VERSION}-fpm AS test
COPY --from=builder /out/quanta_db.so /usr/local/lib/php/extensions/quanta_db.so
COPY --from=builder /out/qdbd /usr/local/bin/qdbd
COPY --from=builder /out/qdbstat /usr/local/bin/qdbstat
COPY tests /ext/tests
# Runs the conformance suite in BOTH modes (fallback + a live qdbd per test).
RUN php -n -d extension=/usr/local/lib/php/extensions/quanta_db.so -m | grep -qi quanta_db \
    && QDB_EXT=/usr/local/lib/php/extensions/quanta_db.so QDBD_BIN=/usr/local/bin/qdbd \
       sh /ext/tests/run-tests.sh
