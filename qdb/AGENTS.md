# AGENTS.md — qdb (Rust extension, daemon, monitor)

Applies to everything under `qdb/`. The repository-wide rules are in `../AGENTS.md`.

## What it is

One crate, three outputs (`Cargo.toml`):

| Output | Role |
|---|---|
| `quanta_db.so` (cdylib, `src/lib.rs`) | PHP extension exposing the static class `QuantaDb`. Reads come from shared memory; writes are done under a lock and then announced to the daemon. |
| `qdbd` (`src/bin/qdbd.rs`) | The only writer of the shared-memory data segment. Scans the site, follows inotify, reconciles periodically. |
| `qdbstat` (`src/bin/qdbstat.rs`) | Read-only monitor, Prometheus exporter and web dashboard. |

The node files stay the source of truth, and qdb is never load-bearing. Without a coherent daemon
the extension answers from the filesystem (`fallback`). Without the `.so`, PHP uses the plain
`FilesDb` (`noext`). Quanta must behave identically in all three modes.

## Rules

- **Every module except `lib.rs` stays PHP-free** (std + `libc` + `serde_json`). The binaries pull
  them in with `#[path]` includes and don't link PHP. Configuration that needs `ini_get` is injected
  from `lib.rs`.
- **Never fail MINIT.** If an ABI or self-test check doubts the PHP build, disable the affected
  fast path and keep serving.
- **Bump the format version on any layout change:** `LAYOUT_VERSION` (`src/shm.rs`), `VERSION`
  (`src/metrics.rs`), `VERSION` (`src/image.rs`). On a version skew, readers fall back instead of
  misreading.
- **The PHP-facing contract is versioned:** `CONTRACT_VERSION` (`src/lib.rs`, returned by
  `QuantaDb::version()`). Change it together with `docs/api-contract.md` (normative),
  `docs/quanta_db.stub.php` and `docs/usage.md`.
- **Readers are lock-free.** Records are immutable and every access is bounds-checked. Use
  Release/Acquire only where `docs/how-it-works.md` says (slots, magic, ready, data epoch);
  everything else is Relaxed. Write invariants as prose next to the `unsafe` block.
- **`qdbd` runs only under supervisord** (`docker/supervisord.conf`). Two daemons would write the
  same segment.
- **Errors:** `DbError` (`src/error.rs`) maps one-to-one onto `QuantaDbException` codes (`IO`,
  `LOCK_TIMEOUT`, `EXISTS`, `BAD_ARGS`, `CORRUPT_JSON`). "Not found" is never an exception.
- **Config binds once per PHP process, at the first `QuantaDb` call.** A later `ini_set` is ignored.
- **A root process creates entries as the directory's owner.** Wrap every new file or directory
  creation in `own::of(<parent dir>)` (`src/own.rs`, per-thread `setfsuid`/`setfsgid`). Never
  `chown` afterwards: the directories are writable by the site's user, so a root chown by path can
  be redirected through a symlink.
- **The PHP side** is `src/modules/environment/classes/Common/FilesDbExt.class.php`: ask the
  extension first, otherwise `return parent::…()` (`FilesDb`). It is the only place that calls
  `\QuantaDb::`. Write failures are rethrown as `FilesDbException` and never retried against the
  filesystem. To add or change a method, follow the checklist in
  `../docs/reference/recipes.md#add-or-change-a-node-storage-method`.
- **Comment style:** `//!` headers state the module's role and whether it is PHP-free, and long
  `///` comments explain why. Keep that up.
- **Pin versions deliberately:** `ext-php-rs` bounds the supported PHP versions, and
  `serde_json`'s `preserve_order` is part of parity (key order).

## Build and test

```sh
sh qdb/tests/run-in-docker.sh               # both suites against a real build (from repo root)
sh qdb/tests/run-in-docker.sh conformance   # extension contract only (fast loop on Rust changes)
sh qdb/tests/run-in-docker.sh parity        # Quanta call sites: noext + fallback + daemon
QDB_REBUILD=1 sh qdb/tests/run-in-docker.sh # force a rebuild first
cd qdb && cargo test                        # Rust unit tests (not run in CI)
```

- The runner uses `--shm-size=256m`. With Docker's default 64m, `08_compaction` fails because the
  daemon keeps the previous segment during compaction.
- Before trusting a red result, compare against unmodified code by pointing `QDB_SRC` at a
  `git archive HEAD` copy (usage at the top of `tests/run-in-docker.sh`). A failure present in
  both is not your change.
- Benchmarks (`tests/run-bench.sh`) only mean something in daemon mode: confirm
  `QuantaDb::stats()['mode'] === 'shm'`.
- CI (`.github/workflows/docker-image.yml`) runs both suites on amd64 **and** arm64. PHP's string
  hash depends on `char` signedness (`src/php_abi.rs`), so keep the code portable.

## Test layout

- `tests/php/NN_*.php` + `_harness.php`: contract conformance, run with
  `php -n -d extension=…` in fallback and daemon mode.
- `tests/quanta/NN_*.php` + `_bootstrap.php`: boot a real `Environment` on a throwaway site.
  `ok`/`eq` must hold in every mode, `*_ext` assertions skip noext, and daemon-only checks need
  `qdbd`. `06_wired.php` asserts that the extension's counters actually move, which catches a
  call site silently falling back.

## Read more

- `docs/usage.md`: the `QuantaDb` API, recipes, and how `Node`/`NodeFactory`/`Environment` use it
- `docs/how-it-works.md`: daemon, shared-memory layout, read/write paths, coherence, fallback
- `docs/api-contract.md`: the normative, versioned specification
- `README.md`: building, `qdbstat` flags, benchmarks, the dev loop
