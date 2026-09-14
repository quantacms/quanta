# Quanta Documentation

## The database

Quanta is 100% filesystem-based: every node is a directory, and its content
lives in a `data.json` (plus `data_<lang>.json` for translations) inside it.
There is no SQL and no separate schema — the filesystem is the single source of
truth.

The `quanta_db` PHP extension ("qdb") is a fast path onto exactly those
files: a daemon keeps the whole tree in shared memory so a node lookup is a hash
probe instead of an `exec(find)`, and writes become atomic and locked. It is
never load-bearing — when it is absent or its daemon is down, Quanta falls back
to the original filesystem code and behaves exactly as it always did.

| Document | |
|---|---|
| [Using qdb from PHP](../qdb/docs/usage.md) | How to read and write nodes through the `QuantaDb` API — methods, options, errors, recipes, and how Quanta's own `Node`/`NodeFactory`/`Environment` classes use it. |
| [How qdb works](../qdb/docs/how-it-works.md) | Internals — the daemon, the shared-memory layout, the read and write paths, coherence and fallback. |
| [API contract](../qdb/docs/api-contract.md) | The normative, versioned specification. |
| [qdb README](../qdb/README.md) | Building, testing, benchmarking, and running `qdbd` / `qdbstat`. |

---

For more information about Quanta, visit the
[official website](https://www.quanta.org).
