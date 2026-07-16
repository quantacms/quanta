<?php

/**
 * quanta_db — Files-DB API stub (contract v1.1).
 *
 * Normative signatures for both implementations:
 *  - the PHP polyfill (_modules/quanta_db/), loaded when the extension is absent;
 *  - the native extension (this file doubles as its php-src-style .stub.php,
 *    from which arginfo is generated with gen_stub.php).
 *
 * The API is a single class, QuantaDb, whose operations are static methods
 * (there are no procedural quanta_db_* functions). Full semantics:
 * docs/files-db/api-contract.md. This file must never be require()d at
 * runtime — it exists for IDEs, static analysis, and arginfo.
 *
 * Conventions repeated from the contract:
 *  - $name is a node basename, never a path.
 *  - Data documents are arrays (json_decode(..., true) shape). Returned arrays
 *    may be shared/COW: treat them as immutable.
 *  - "Not found" returns null/false; real failures throw QuantaDbException.
 */

/** Thrown for I/O, locking, argument and corruption failures — never "not found". */
class QuantaDbException extends \RuntimeException
{
    public const IO = 1;
    public const LOCK_TIMEOUT = 2;
    public const EXISTS = 3;
    public const BAD_ARGS = 4;
    public const CORRUPT_JSON = 5;
}

/**
 * The Files-DB interface. All methods are static; configuration
 * (quanta_db.root, …) is process-global, read from php.ini / env.
 */
class QuantaDb
{
    /* ── Reads ─────────────────────────────────────────────────────────── */

    /**
     * Decoded data document of a node, or null if node/language file is absent.
     * No language fallback (that policy stays in NodeFactory).
     *
     * @param string      $name node basename
     * @param string|null $lang null = data.json, 'it' = data_it.json, …
     */
    public static function get(string $name, ?string $lang = null): ?array {}

    /**
     * Raw JSON document of a node as a string (the exact file/segment bytes),
     * or null if the node/language file is absent. Pairs a zero-syscall
     * shared-memory read with PHP's native json_decode at hot call sites,
     * avoiding the Rust-side array construction get() pays for. (Contract 1.1.)
     *
     * @param string      $name node basename
     * @param string|null $lang null = data.json, 'it' = data_it.json, …
     */
    public static function getRaw(string $name, ?string $lang = null): ?string {}

    /** Absolute directory path of the node, or null. Replaces Environment::nodePath(). */
    public static function path(string $name): ?string {}

    public static function exists(string $name): bool {}

    /**
     * Derived facts about a node, or null.
     *
     * @return array{path: string, father: ?string, mtime: int, generation: int,
     *               langs: string[], is_link_target_of: int}|null
     */
    public static function meta(string $name): ?array {}

    /**
     * Names of direct children of $father.
     *
     * @param array{type?: 'all'|'dirs'|'links', include_hidden?: bool} $opts
     * @return string[]
     */
    public static function children(string $father, array $opts = []): array {}

    /**
     * Container nodes holding a symlink to $target (categories, statuses).
     *
     * @return string[]
     */
    public static function links(string $target): array {}

    /* ── Queries ───────────────────────────────────────────────────────── */

    /**
     * @param array{father?: string, lineage?: string, in?: string,
     *              where?: array<string, scalar>, name_prefix?: string} $criteria
     * @param array{return?: 'names'|'data'|'meta', order_by?: string,
     *              order?: 'asc'|'desc', limit?: int, offset?: int,
     *              lang?: ?string} $opts
     * @return array `return=names`: string[]; `data`: array<string, array>; `meta`: array<string, array>
     */
    public static function find(array $criteria, array $opts = []): array {}

    /** @param array{father?: string, lineage?: string, in?: string, where?: array<string, scalar>, name_prefix?: string} $criteria */
    public static function count(array $criteria): int {}

    /* ── Writes ────────────────────────────────────────────────────────── */

    /**
     * Replace the node's data document (tmp-file + atomic rename; bumps generation).
     * Passing $opts['father'] declares create intent: creates the node (atomic
     * mkdir) or throws QuantaDbException::EXISTS if the name is taken anywhere.
     * Update existing nodes by calling put without 'father'.
     *
     * @param array{lang?: ?string, father?: string} $opts
     */
    public static function put(string $name, array $data, array $opts = []): bool {}

    /**
     * Locked read-modify-write. $fn(?array $current): ?array — return the new
     * document to persist, or null to abort. Not reentrant on the same node.
     *
     * @param callable(?array): ?array      $fn
     * @param array{lang?: ?string}         $opts
     * @return array|null whatever $fn returned
     */
    public static function update(string $name, callable $fn, array $opts = []): ?array {}

    /** Move the node dir to the trashbin, drop its index rows and inbound links. */
    public static function delete(string $name): bool {}

    /**
     * Symlink $target into $container's directory.
     *
     * @param array{if_exists?: 'ignore'|'error'} $opts
     */
    public static function link(string $target, string $container, array $opts = []): bool {}

    /** @param array{if_not_exists?: 'ignore'|'error'} $opts */
    public static function unlink(string $target, string $container, array $opts = []): bool {}

    /**
     * Atomic unlink+link under the target's lock — the primitive behind
     * BookingFactory::changeBookingStatus(); readers never see the target in
     * zero or two containers.
     */
    public static function relink(string $target, string $from_container, string $to_container): bool {}

    /* ── Maintenance / introspection ───────────────────────────────────── */

    /**
     * Rebuild derived data (index, caches) from the filesystem. Safe under traffic.
     *
     * @return array{nodes: int, links: int, seconds: float}
     */
    public static function reindex(?string $subtree = null): array {}

    /** @return array{implementation: string, contract: string, nodes: int, ...} */
    public static function stats(): array {}

    /** Implementation id, e.g. 'polyfill/1.1' or 'ext/1.1'. */
    public static function version(): string {}

    /**
     * Whether a live daemon (qdbd) is serving the shared-memory data segment
     * right now (fresh heartbeat + coherence flag). When true, a null lookup is
     * a definitive absence, so callers may skip a legacy filesystem fallback.
     * When false (no daemon / stale / metrics off), treat null as "unknown".
     */
    public static function coherent(): bool {}
}
