<?php

/**
 * quanta_db — qdb API stub (contract v1.3).
 *
 * Normative signatures for the native extension; this file doubles as its
 * php-src-style .stub.php, from which arginfo is generated with gen_stub.php.
 * (The contract also describes a pure-PHP polyfill under _modules/quanta_db/;
 * that has never been shipped — see api-contract.md.)
 *
 * The API is a single class, QuantaDb, whose operations are static methods
 * (there are no procedural quanta_db_* functions). Practical guide:
 * qdb/docs/usage.md. Full normative semantics:
 * qdb/docs/api-contract.md. This file must never be require()d at
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
 * The qdb interface. All methods are static; configuration
 * (quanta_db.root, …) is process-global, read from php.ini / env.
 */
class QuantaDb
{
    /* ── Reads ─────────────────────────────────────────────────────────── */

    /**
     * Decoded data document of a node, or null if node/language file is absent.
     * No language fallback — this primitive reads exactly one file. Callers
     * wanting Quanta's "translation first, then neutral" order either ask twice
     * or use load(), which is the composed operation offered ALONGSIDE these
     * primitives, not a replacement for them.
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

    /**
     * Data document as a stdClass — exactly what `(object) json_decode($raw)`
     * produces, nested shapes included (JSON objects become stdClass, JSON
     * arrays become lists). Null if the node/language file is absent.
     *
     * The reader behind Node::loadJSON(). `(object) get()` is NOT equivalent:
     * that cast converts only the top level, leaving nested JSON objects as
     * arrays, and Quanta reads them as objects
     * ($node->json->permissions->{$permission}).
     *
     * Unlike every other reader here, the result is freshly allocated,
     * unshared and fully MUTABLE — see contract §3. (Contract 1.2.)
     *
     * @param string      $name node basename
     * @param string|null $lang null = data.json, 'it' = data_it.json, …
     */
    public static function getObject(string $name, ?string $lang = null): ?object {}

    /**
     * One node's document with language fallback and a path-identity check
     * applied INSIDE the single index probe that already holds the record.
     * (Contract 1.3.)
     *
     * The composed operation, offered alongside the primitives above rather
     * than replacing them: "load this node's document, in this language or the
     * neutral one, having confirmed it is the node in this directory" is what
     * Node::loadJSON does on every node of every page, and expressing it with
     * path() + getObject() + getObject() costs three probes and one absolute
     * path string that exists only to be compared once in PHP.
     *
     * $opts:
     *  - lang     : language to try first; null / '' = the neutral document.
     *  - fallback : default true — retry the neutral document when 'lang' has
     *               none. This is the one place the API takes a position on
     *               language policy, and only because the caller asked for it.
     *  - at       : the directory the caller believes this node lives in, in
     *               the EXTENSION's root terms (quanta_db.root), never a site
     *               docroot. Given, the identity check happens against the
     *               record and no path is built.
     *  - as       : 'object' (default; getObject() shape all the way down) or
     *               'array' (get() shape).
     *
     * Returns null for "no such node", "it is not the node at 'at'" and "no
     * document in any language tried" alike. A corrupt document still throws
     * CORRUPT_JSON, exactly as getObject() does — that is an error, not an
     * absence.
     *
     * 'path' is in the result ONLY when 'at' was not supplied: passing 'at' is
     * the caller saying it already knows where the node is, and building the
     * path anyway is the allocation this method exists to remove.
     *
     * @param array{lang?: ?string, fallback?: bool, at?: string,
     *              as?: 'object'|'array'} $opts
     * @return array{json: object|array, lang: string, generation: int,
     *               path?: string}|null
     */
    public static function load(string $name, array $opts = []): ?array {}

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

    /**
     * put() for callers that already hold the serialized document. The bytes are
     * stored VERBATIM — json_encode() escapes '/' and non-ASCII where the
     * extension's own encoder does not, so this is how a document stays
     * byte-stable across writers. $json must parse; if it does not, nothing is
     * written and QuantaDbException::BAD_ARGS is thrown.
     *
     * @param array{lang?: ?string, father?: string} $opts
     */
    public static function putRaw(string $name, string $json, array $opts = []): bool {}

    /**
     * Remove one language's document (data.json / data_<lang>.json); the node
     * stays. False when that file (or the node) was not there. A node with no
     * documents still resolves via path()/exists(); get() returns null.
     */
    public static function deleteDoc(string $name, ?string $lang = null): bool {}

    /**
     * Relocate a node: new father, new name ($opts['name']), or both. The
     * directory is renamed, so the whole subtree travels with it, and every
     * inbound link is re-pointed (links hold absolute paths and would otherwise
     * dangle). False when $name does not resolve; EXISTS when the destination
     * is occupied or a rename would take a name already used in the tree;
     * BAD_ARGS when the new father is inside the node's own subtree.
     *
     * Atomic per step, not end to end: a crash between the rename and the link
     * re-pointing leaves dangling links that reindex() repairs.
     *
     * @param array{name?: string, if_exists?: 'error'|'replace'} $opts
     */
    public static function move(string $name, ?string $new_father = null, array $opts = []): bool {}

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
