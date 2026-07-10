# syntax=docker/dockerfile:1
# Build + conformance-test image for the quanta_db PHP extension.
# Built against php:8.2-apache so the .so matches the production ABI (NTS).
#
#   docker build -t quanta-db quanta/files-db/            # builds ext AND runs tests
#   docker build --target artifact -o dist quanta/files-db/   # extract quanta_db.so

FROM php:8.2-apache AS builder-base
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl build-essential clang libclang-dev pkg-config git \
    && rm -rf /var/lib/apt/lists/*
ENV RUSTUP_HOME=/opt/rustup CARGO_HOME=/opt/cargo PATH=/opt/cargo/bin:$PATH
RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs \
    | sh -s -- -y --profile minimal --default-toolchain stable

FROM builder-base AS builder
WORKDIR /ext
COPY Cargo.toml ./
COPY src ./src
RUN --mount=type=cache,target=/opt/cargo/registry \
    --mount=type=cache,target=/ext/target \
    cargo build --release \
    && install -D target/release/libquanta_db.so /out/quanta_db.so

FROM scratch AS artifact
COPY --from=builder /out/quanta_db.so /quanta_db.so

FROM php:8.2-apache AS test
COPY --from=builder /out/quanta_db.so /usr/local/lib/php/extensions/quanta_db.so
COPY tests /ext/tests
RUN php -n -d extension=/usr/local/lib/php/extensions/quanta_db.so -m | grep -qi quanta_db \
    && QDB_EXT=/usr/local/lib/php/extensions/quanta_db.so sh /ext/tests/run-tests.sh
