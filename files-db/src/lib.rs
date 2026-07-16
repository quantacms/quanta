//! quanta_db — PHP extension implementing the Files-DB API contract v1.1
//! (docs/files-db/api-contract.md). The JSON files stay the source of truth;
//! everything else is derived, rebuildable data.
//!
//! Primary mode: the `qdbd` daemon holds the whole tree (paths, children,
//! links, raw docs) in a shared-memory segment; while its heartbeat is fresh
//! every read here is a hash probe into that mapping — no filesystem access.
//! Writes go to the files (flock + atomic rename, unchanged) and are then
//! pushed to the daemon over a unix socket; the daemon acks only after
//! publishing to SHM, preserving cross-process read-your-writes (§4.1).
//!
//! Fallback mode (no healthy daemon): a per-process walk snapshot replaces the
//! index and documents are read straight from disk, uncached — i.e. legacy
//! behavior. Caches only ever serve the fallback path or skip re-parsing.
#![cfg_attr(windows, feature(abi_vectorcall))]

mod cache;
mod config;
mod error;
mod ipc;
mod lock;
mod metrics;
mod model;
mod paths;
mod shm;
mod store;

use std::cell::RefCell;
use std::collections::HashSet;
use std::fs;
use std::path::{Path, PathBuf};
use std::rc::Rc;
use std::sync::OnceLock;
use std::time::{Duration, Instant};

use ext_php_rs::exception::PhpException;
use ext_php_rs::flags::IniEntryPermission;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ArrayKey, ZendHashTable, Zval};
use ext_php_rs::zend::{ce, ClassEntry, IniEntryDef};
use serde_json::Value;

use config::{Config, VerifyReads};
use error::DbError;
use shm::Lookup;

const CONTRACT_VERSION: &str = "1.1";

type PhpResult<T> = Result<T, PhpException>;

/// Resolved node facts, produced from the SHM record or the fallback snapshot.
pub struct NodeRow {
    pub name: String,
    pub path: String,
    pub father: Option<String>,
    pub generation: i64,
}

// ---------------------------------------------------------------------------
// Config resolution (PHP-aware side; config.rs itself is PHP-free)
// ---------------------------------------------------------------------------

/// Read one php.ini value via ext-php-rs. Returns None when unset/empty so the
/// env fallback in `config::build_with` can take over.
fn ini_get(name: &str) -> Option<String> {
    let func = ext_php_rs::zend::Function::try_from_function("ini_get")?;
    let ret = func.try_call(vec![&name]).ok()?;
    ret.string()
}

/// Config is resolved once per process at first use (after `ini_set` is still
/// possible, before it is not). Documented limitation of v1. Best-effort metrics
/// arena init; failure there never disables the DB (it does disable SHM mode,
/// since the arena carries the coherence heartbeat + active epoch).
fn config() -> Result<&'static Config, DbError> {
    static CONFIG: OnceLock<Result<Config, String>> = OnceLock::new();
    let cfg = CONFIG.get_or_init(|| {
        let cfg = config::build_with(ini_get);
        if let Ok(c) = &cfg {
            metrics::init(&c.metrics_path, c.metrics);
        }
        cfg
    });
    cfg.as_ref().map_err(|e| DbError::BadArgs(e.clone()))
}

/// PHP exception class for DbError conversion (moved here from error.rs so that
/// module stays PHP-free for the standalone binaries).
fn exception_ce() -> &'static ClassEntry {
    ClassEntry::try_find("QuantaDbException").unwrap_or_else(ce::exception)
}

impl From<DbError> for PhpException {
    fn from(e: DbError) -> Self {
        PhpException::new(e.message().to_string(), e.code(), exception_ce())
    }
}

// ---------------------------------------------------------------------------
// Exception class (contract §7)
// ---------------------------------------------------------------------------

// SPL exports this class-entry pointer; resolved by the PHP binary at dlopen.
// Needed because EG(class_table) is not queryable during MINIT, when the
// parent class of QuantaDbException is resolved.
extern "C" {
    static spl_ce_RuntimeException: *mut ClassEntry;
}

fn runtime_exception_ce() -> &'static ClassEntry {
    unsafe { spl_ce_RuntimeException.as_ref() }.unwrap_or_else(ce::exception)
}

#[php_class]
#[php(name = "QuantaDbException")]
#[php(extends(ce = runtime_exception_ce, stub = "\\RuntimeException"))]
#[derive(Default)]
pub struct QuantaDbException;

#[php_impl]
impl QuantaDbException {
    const IO: i32 = 1;
    const LOCK_TIMEOUT: i32 = 2;
    const EXISTS: i32 = 3;
    const BAD_ARGS: i32 = 4;
    const CORRUPT_JSON: i32 = 5;
}

// ---------------------------------------------------------------------------
// Zval <-> JSON conversion (arrays at the boundary, contract §1.2)
// ---------------------------------------------------------------------------

fn json_to_zval(v: &Value) -> Result<Zval, DbError> {
    let mut z = Zval::new();
    match v {
        Value::Null => z.set_null(),
        Value::Bool(b) => z.set_bool(*b),
        Value::Number(n) => {
            if let Some(i) = n.as_i64() {
                z.set_long(i);
            } else {
                z.set_double(n.as_f64().unwrap_or(0.0));
            }
        }
        Value::String(s) => z
            .set_string(s, false)
            .map_err(|e| DbError::Io(format!("string conversion: {e}")))?,
        Value::Array(items) => {
            let mut ht = ZendHashTable::new();
            for item in items {
                ht.push(json_to_zval(item)?)
                    .map_err(|e| DbError::Io(format!("array conversion: {e}")))?;
            }
            z.set_hashtable(ht);
        }
        Value::Object(map) => {
            let mut ht = ZendHashTable::new();
            for (k, val) in map {
                ht.insert(ArrayKey::from(k.clone()), json_to_zval(val)?)
                    .map_err(|e| DbError::Io(format!("array conversion: {e}")))?;
            }
            z.set_hashtable(ht);
        }
    }
    Ok(z)
}

fn key_to_string(k: &ArrayKey) -> String {
    match k {
        ArrayKey::Long(l) => l.to_string(),
        ArrayKey::String(s) => s.clone(),
        ArrayKey::Str(s) => (*s).to_string(),
        ArrayKey::ZendString(z) => String::from_utf8_lossy(z.as_bytes()).into_owned(),
    }
}

fn zval_to_json(z: &Zval) -> Result<Value, DbError> {
    if z.is_null() {
        return Ok(Value::Null);
    }
    if let Some(b) = z.bool() {
        return Ok(Value::Bool(b));
    }
    if let Some(l) = z.long() {
        return Ok(Value::from(l));
    }
    if let Some(d) = z.double() {
        return Ok(Value::from(d));
    }
    if let Some(s) = z.str() {
        return Ok(Value::String(s.to_string()));
    }
    if let Some(ht) = z.array() {
        return table_to_json(ht);
    }
    Err(DbError::BadArgs(
        "unsupported value in data document (allowed: null, bool, int, float, string, array)"
            .to_string(),
    ))
}

fn table_to_json(ht: &ZendHashTable) -> Result<Value, DbError> {
    // Sequential integer keys from 0 -> JSON list, else JSON object
    // (same rule json_encode uses).
    let mut is_list = true;
    let mut expected = 0i64;
    for (key, _) in ht.iter() {
        match key {
            ArrayKey::Long(i) if i == expected => expected += 1,
            _ => {
                is_list = false;
                break;
            }
        }
    }
    if is_list {
        let mut out = Vec::new();
        for (_, v) in ht.iter() {
            out.push(zval_to_json(v)?);
        }
        Ok(Value::Array(out))
    } else {
        let mut out = serde_json::Map::new();
        for (k, v) in ht.iter() {
            out.insert(key_to_string(&k), zval_to_json(v)?);
        }
        Ok(Value::Object(out))
    }
}

fn null_zval() -> Zval {
    let mut z = Zval::new();
    z.set_null();
    z
}

fn strings_to_zval(items: &[String]) -> Result<Zval, DbError> {
    let mut ht = ZendHashTable::new();
    for s in items {
        ht.push(s.as_str())
            .map_err(|e| DbError::Io(format!("array conversion: {e}")))?;
    }
    let mut z = Zval::new();
    z.set_hashtable(ht);
    Ok(z)
}

// ---------------------------------------------------------------------------
// Option / criteria parsing (unknown keys throw BAD_ARGS, contract §8)
// ---------------------------------------------------------------------------

struct Reader<'a> {
    entries: Vec<(String, &'a Zval)>,
    ctx: &'static str,
}

impl<'a> Reader<'a> {
    fn new(
        ht: Option<&'a ZendHashTable>,
        allowed: &[&str],
        ctx: &'static str,
    ) -> Result<Self, DbError> {
        let mut entries = Vec::new();
        if let Some(ht) = ht {
            for (k, v) in ht.iter() {
                let key = key_to_string(&k);
                if !allowed.contains(&key.as_str()) {
                    return Err(DbError::BadArgs(format!("unknown {ctx} key '{key}'")));
                }
                entries.push((key, v));
            }
        }
        Ok(Self { entries, ctx })
    }

    fn raw(&self, key: &str) -> Option<&'a Zval> {
        self.entries
            .iter()
            .find(|(k, _)| k == key)
            .map(|(_, v)| *v)
    }

    fn str_opt(&self, key: &str) -> Result<Option<String>, DbError> {
        match self.raw(key) {
            None => Ok(None),
            Some(z) if z.is_null() => Ok(None),
            Some(z) => z.str().map(|s| Some(s.to_string())).ok_or_else(|| {
                DbError::BadArgs(format!("{} key '{key}' must be a string", self.ctx))
            }),
        }
    }

    fn long_opt(&self, key: &str) -> Result<Option<i64>, DbError> {
        match self.raw(key) {
            None => Ok(None),
            Some(z) if z.is_null() => Ok(None),
            Some(z) => z.long().map(Some).ok_or_else(|| {
                DbError::BadArgs(format!("{} key '{key}' must be an int", self.ctx))
            }),
        }
    }
}

fn normalize_lang(lang: Option<String>) -> Result<String, DbError> {
    let lang = lang.unwrap_or_default();
    if !lang
        .chars()
        .all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '_')
    {
        return Err(DbError::BadArgs(format!("invalid language code '{lang}'")));
    }
    Ok(lang)
}

fn ensure_valid_name(name: &str) -> Result<(), DbError> {
    if name.is_empty()
        || name == "."
        || name == ".."
        || name.contains('/')
        || name.contains('\\')
        || name.contains('\0')
    {
        return Err(DbError::BadArgs(format!("invalid node name '{name}'")));
    }
    Ok(())
}

// ---------------------------------------------------------------------------
// SHM segment access (primary mode)
// ---------------------------------------------------------------------------

thread_local! {
    /// The currently mapped data segment. Replaced (old mapping munmap'd via
    /// Drop) whenever the daemon publishes a new epoch.
    static SEG: RefCell<Option<Rc<shm::SegmentReader>>> = const { RefCell::new(None) };
}

/// The active segment, or None => this call runs in fallback mode.
/// Cheap on the hot path: two atomic loads + an epoch compare.
fn seg_current(cfg: &Config) -> Option<Rc<shm::SegmentReader>> {
    if !metrics::index_coherent() {
        return None;
    }
    let epoch = metrics::data_epoch();
    if epoch == 0 {
        return None;
    }
    SEG.with(|s| {
        let mut opt = s.borrow_mut();
        if let Some(r) = opt.as_ref() {
            if r.epoch == epoch {
                return Some(r.clone());
            }
        }
        // Epoch flip (or first use): remap. A reader can race a compaction
        // (file already replaced again / briefly missing) — re-read the epoch
        // and retry a couple of times before giving this call to fallback.
        for _ in 0..3 {
            let e = metrics::data_epoch();
            if e == 0 {
                break;
            }
            match shm::SegmentReader::open(&cfg.shm_dir, e, cfg.root_hash) {
                Ok(r) => {
                    metrics::shm_remap();
                    let rc = Rc::new(r);
                    *opt = Some(rc.clone());
                    return Some(rc);
                }
                Err(_) => continue,
            }
        }
        *opt = None;
        None
    })
}

/// Notify the daemon of a write and wait for the ack (which it sends only
/// AFTER publishing to SHM — read-your-writes, §4.1).
///
/// Gated purely on coherence: when a daemon is coherent, reads trust its
/// segment, so a write MUST reach it (or coherence must be poisoned) — we
/// always attempt, and on any failure flip every worker to fallback by
/// clearing the flag (a silently-dead daemon otherwise keeps a fresh-looking
/// heartbeat for up to the staleness window). When NOT coherent we are already
/// in fallback: reads don't consult the segment and a returning daemon
/// reconciles from disk, so there is nothing to notify and no socket to poke.
fn notify_daemon(cfg: &Config, msg: serde_json::Value) -> Option<serde_json::Value> {
    if !metrics::index_coherent() {
        return None;
    }
    let timeout = Duration::from_millis(cfg.write_ack_timeout_ms.max(1));
    match ipc::request(&cfg.socket_path, timeout, &msg) {
        Ok(ack) if ipc::ack_ok(&ack) => {
            metrics::uds_notify();
            Some(ack)
        }
        _ => {
            metrics::uds_failure();
            ipc::reset();
            metrics::set_coherent(false);
            None
        }
    }
}

// ---------------------------------------------------------------------------
// Core node resolution / document loading (contract §4)
// ---------------------------------------------------------------------------

fn row_from_snap(cfg: &Config, name: &str, e: &cache::SnapEntry) -> NodeRow {
    let _ = cfg;
    NodeRow {
        name: name.to_string(),
        path: e.path.to_string_lossy().to_string(),
        father: e.father.clone(),
        generation: cache::gen_of(name),
    }
}

/// Resolve a node by name. SHM mode: one hash probe, authoritative both ways.
/// Fallback: walk-snapshot lookup with self-heal rebuild (contract §4.3) and
/// the negative cache bounding repeat misses.
fn resolve_node(cfg: &Config, name: &str) -> Result<Option<NodeRow>, DbError> {
    ensure_valid_name(name)?;
    if let Some(seg) = seg_current(cfg) {
        match seg.lookup(name) {
            Lookup::Found(rec) if !rec.is_tombstone() => {
                metrics::shm_hit();
                return Ok(Some(NodeRow {
                    name: name.to_string(),
                    path: cfg.root.join(rec.rel_path()).to_string_lossy().to_string(),
                    father: rec.father().map(str::to_string),
                    generation: rec.generation as i64,
                }));
            }
            Lookup::Found(_) | Lookup::Absent => {
                // The daemon keeps the segment authoritative; a miss (or
                // tombstone) is a definitive absence — no walk.
                metrics::authoritative_miss();
                return Ok(None);
            }
            Lookup::Invalid => {
                metrics::shm_invalid(); // never trust a failed validation
            }
        }
    }
    resolve_fallback(cfg, name)
}

fn resolve_fallback(cfg: &Config, name: &str) -> Result<Option<NodeRow>, DbError> {
    if let Some(e) = cache::snap_lookup(cfg, name) {
        if e.path.is_dir() {
            return Ok(Some(row_from_snap(cfg, name, &e)));
        }
    }
    // Snapshot miss or a stale path (external create/move/delete). Recently
    // proven absent? Skip the walk (TTL-bounded, contract §4.3 allows it).
    if cfg.neg_cache_ms > 0 && cache::neg_fresh(name, Duration::from_millis(cfg.neg_cache_ms)) {
        return Ok(None);
    }
    // Self-heal: one fresh walk, then answer from it.
    cache::snap_rebuild(cfg);
    if let Some(e) = cache::snap_lookup_raw(name) {
        if e.path.is_dir() {
            metrics::fs_heal();
            return Ok(Some(row_from_snap(cfg, name, &e)));
        }
    }
    metrics::node_miss();
    if cfg.neg_cache_ms > 0 {
        cache::neg_insert(name);
    }
    Ok(None)
}

/// Locate a subtree's on-disk directory for a targeted [`reindex`]. Unlike
/// [`resolve_node`], this deliberately bypasses the authoritative short-circuit
/// and the negative cache: `reindex` is the tool used to REPAIR stale derived
/// data, so it must find a directory that exists on disk even when the daemon
/// has not caught up yet. SHM first (fast, path-validated), then a filesystem
/// search.
fn resolve_subtree_dir(cfg: &Config, name: &str) -> Result<Option<PathBuf>, DbError> {
    ensure_valid_name(name)?;
    if let Some(seg) = seg_current(cfg) {
        if let Lookup::Found(rec) = seg.lookup(name) {
            if !rec.is_tombstone() {
                let p = cfg.root.join(rec.rel_path());
                if p.is_dir() {
                    return Ok(Some(p));
                }
            }
        }
    }
    Ok(store::fs_search(cfg, name))
}

/// Load one document. SHM mode: bytes straight from the record, decoded via
/// the generation-validated parse cache (pure parse-avoidance). Fallback:
/// direct file read, UNCACHED — mtime+size cannot validate same-second writes
/// (contract scenario 5), and files are the source of truth anyway.
fn load_doc(cfg: &Config, row: &NodeRow, lang: &str) -> Result<Option<Rc<Value>>, DbError> {
    let started = Instant::now();
    let r = load_doc_inner(cfg, row, lang);
    metrics::record_read(started.elapsed());
    r
}

fn load_doc_inner(cfg: &Config, row: &NodeRow, lang: &str) -> Result<Option<Rc<Value>>, DbError> {
    if let Some(seg) = seg_current(cfg) {
        if let Lookup::Found(rec) = seg.lookup(&row.name) {
            if !rec.is_tombstone() {
                match rec.doc(lang) {
                    Err(()) => {
                        metrics::corrupt_json();
                        return Err(DbError::CorruptJson(format!(
                            "invalid JSON in {}/{}",
                            row.path,
                            store::doc_file(lang)
                        )));
                    }
                    Ok(None) => return Ok(None),
                    Ok(Some(bytes)) => {
                        metrics::index_serve();
                        return parse_cached(&row.name, lang, bytes, rec.generation as i64)
                            .map(Some);
                    }
                }
            }
        }
        // Absent/tombstoned in SHM while the row resolved a moment ago: a
        // racing delete. Fall through to the file — source of truth.
    }
    let node_path = Path::new(&row.path);
    let Some((raw, _fstat)) =
        store::read_doc(node_path, lang).inspect_err(|_| metrics::io_error())?
    else {
        return Ok(None);
    };
    metrics::file_read(raw.len() as u64);
    metrics::fallback_read();
    let parsed: Value = serde_json::from_str(&raw).map_err(|e| {
        metrics::corrupt_json();
        DbError::CorruptJson(format!(
            "invalid JSON in {}/{}: {e}",
            row.path,
            store::doc_file(lang)
        ))
    })?;
    Ok(Some(Rc::new(parsed)))
}

fn parse_cached(name: &str, lang: &str, raw: &[u8], generation: i64) -> Result<Rc<Value>, DbError> {
    if let Some(v) = cache::get(name, lang, generation) {
        return Ok(v);
    }
    let parsed: Value = serde_json::from_slice(raw).map_err(|e| {
        DbError::CorruptJson(format!("invalid JSON for node '{name}': {e}"))
    })?;
    let rc = Rc::new(parsed);
    cache::put(name, lang, generation, rc.clone());
    Ok(rc)
}

/// The raw JSON bytes of one document, no decoding. SHM mode serves the exact
/// stored file bytes (even corrupt ones); fallback reads the file directly.
fn load_raw(cfg: &Config, row: &NodeRow, lang: &str) -> Result<Option<String>, DbError> {
    if let Some(seg) = seg_current(cfg) {
        if let Lookup::Found(rec) = seg.lookup(&row.name) {
            if !rec.is_tombstone() {
                for l in rec.langs() {
                    if l.lang == lang {
                        metrics::index_serve();
                        return Ok(Some(String::from_utf8_lossy(l.doc).into_owned()));
                    }
                }
                return Ok(None);
            }
        }
    }
    let node_path = Path::new(&row.path);
    match store::read_doc(node_path, lang).inspect_err(|_| metrics::io_error())? {
        Some((raw, _)) => {
            metrics::file_read(raw.len() as u64);
            metrics::fallback_read();
            Ok(Some(raw))
        }
        None => Ok(None),
    }
}

/// Container names holding a symlink to `target` (sorted). SHM: from the
/// record's inlink list (authoritative); fallback: from the walk snapshot.
fn links_of(cfg: &Config, target: &str) -> Result<Vec<String>, DbError> {
    if let Some(seg) = seg_current(cfg) {
        match seg.lookup(target) {
            Lookup::Found(rec) if !rec.is_tombstone() => {
                return Ok(rec.inlinks().into_iter().map(str::to_string).collect());
            }
            Lookup::Found(_) | Lookup::Absent => return Ok(Vec::new()),
            Lookup::Invalid => metrics::shm_invalid(),
        }
    }
    Ok(cache::snap_links_of(cfg, target))
}

/// Write path shared by put/update. Caller must hold the node lock.
fn put_inner(
    cfg: &Config,
    name: &str,
    value: &Value,
    lang: &str,
    father: Option<&str>,
) -> Result<(), DbError> {
    let started = Instant::now();
    let row = resolve_node(cfg, name)?;
    let path: PathBuf = match row {
        Some(r) => {
            // Passing 'father' declares create intent: the name being taken
            // (anywhere in the tree) is the EXISTS error — this is the atomic
            // name-reservation primitive replacing getCandidatePath().
            if father.is_some() {
                return Err(DbError::Exists(format!("node '{name}' already exists")));
            }
            PathBuf::from(r.path)
        }
        None => {
            let father_name = father.ok_or_else(|| {
                DbError::BadArgs(format!(
                    "node '{name}' does not exist; pass opts['father'] to create it"
                ))
            })?;
            ensure_valid_name(father_name)?;
            let f_row = resolve_node(cfg, father_name)?.ok_or_else(|| {
                DbError::BadArgs(format!("father node '{father_name}' not found"))
            })?;
            let new_path = Path::new(&f_row.path).join(name);
            // Atomic name reservation (contract §3 put): mkdir fails if taken.
            match fs::create_dir(&new_path) {
                Ok(()) => {}
                Err(e) if e.kind() == std::io::ErrorKind::AlreadyExists => {
                    return Err(DbError::Exists(format!("node '{name}' already exists")))
                }
                Err(e) => return Err(e.into()),
            }
            new_path
        }
    };
    let raw = serde_json::to_string(value).map_err(|e| DbError::Io(e.to_string()))?;
    store::write_doc(&path, lang, &raw)?;
    let father_db = store::father_of(cfg, &path);

    // Push to the daemon (single SHM writer); its ack means "published".
    let rel = path
        .strip_prefix(&cfg.root)
        .map(|p| p.to_string_lossy().to_string())
        .unwrap_or_else(|_| path.to_string_lossy().to_string());
    let ack = notify_daemon(cfg, ipc::msg_upsert(name, &rel));

    cache::invalidate(name);
    // The create-intent resolve above proved the name absent and neg-cached it;
    // it now exists, so drop that verdict to make it resolvable immediately.
    cache::neg_remove(name);
    cache::snap_note_put(name, &path, father_db.as_deref());
    let generation = match ack.as_ref().and_then(|a| ipc::ack_u64(a, "generation")) {
        Some(g) => g as i64,
        None => cache::gen_note_write(name),
    };
    // Seed the parse cache: the writer's own next read skips re-parsing.
    if ack.is_some() {
        if let Ok(parsed) = serde_json::from_str::<Value>(&raw) {
            cache::put(name, lang, generation, Rc::new(parsed));
        }
    }
    metrics::record_write(started.elapsed(), raw.len() as u64);
    Ok(())
}

// ---------------------------------------------------------------------------
// children / meta helpers
// ---------------------------------------------------------------------------

#[derive(PartialEq, Clone, Copy)]
enum ChildType {
    All,
    Dirs,
    Links,
}

/// The unfiltered (name, is_link) child list of a resolved node — from the SHM
/// record when serving, from `read_dir` in fallback (identical semantics; the
/// daemon stores exactly `store::list_children`).
fn child_list(cfg: &Config, row: &NodeRow) -> Vec<(String, bool)> {
    if let Some(seg) = seg_current(cfg) {
        if let Lookup::Found(rec) = seg.lookup(&row.name) {
            if !rec.is_tombstone() {
                return rec
                    .children()
                    .into_iter()
                    .map(|(n, l)| (n.to_string(), l))
                    .collect();
            }
        }
    }
    store::list_children(Path::new(&row.path))
}

fn children_impl(
    cfg: &Config,
    father: &str,
    ctype: ChildType,
    include_hidden: bool,
) -> Result<Vec<String>, DbError> {
    let Some(row) = resolve_node(cfg, father)? else {
        return Ok(Vec::new());
    };
    let mut out = Vec::new();
    for (name, is_link) in child_list(cfg, &row) {
        if !include_hidden && name.starts_with('_') {
            continue;
        }
        match ctype {
            ChildType::All => {}
            ChildType::Dirs if is_link => continue,
            ChildType::Links if !is_link => continue,
            _ => {}
        }
        out.push(name);
    }
    Ok(out) // already sorted (both sources sort)
}

fn node_mtime(cfg: &Config, row: &NodeRow) -> i64 {
    if let Some(seg) = seg_current(cfg) {
        if let Lookup::Found(rec) = seg.lookup(&row.name) {
            if !rec.is_tombstone() {
                return rec.mtime;
            }
        }
    }
    store::node_mtime(Path::new(&row.path))
}

fn node_langs(cfg: &Config, row: &NodeRow) -> Vec<String> {
    if let Some(seg) = seg_current(cfg) {
        if let Lookup::Found(rec) = seg.lookup(&row.name) {
            if !rec.is_tombstone() {
                return rec.langs().into_iter().map(|l| l.lang.to_string()).collect();
            }
        }
    }
    store::langs_of(Path::new(&row.path))
}

fn meta_zval(cfg: &Config, row: &NodeRow) -> Result<Zval, DbError> {
    let langs = node_langs(cfg, row);
    let inbound = links_of(cfg, &row.name)?;
    let mut ht = ZendHashTable::new();
    let ins = |ht: &mut ZendHashTable, k: &str, v: Zval| -> Result<(), DbError> {
        ht.insert(ArrayKey::Str(k), v)
            .map_err(|e| DbError::Io(format!("array conversion: {e}")))
    };
    let mut z = Zval::new();
    z.set_string(&row.path, false)
        .map_err(|e| DbError::Io(e.to_string()))?;
    ins(&mut ht, "path", z)?;
    let father_z = match &row.father {
        Some(f) => {
            let mut z = Zval::new();
            z.set_string(f, false).map_err(|e| DbError::Io(e.to_string()))?;
            z
        }
        None => null_zval(),
    };
    ins(&mut ht, "father", father_z)?;
    let mut z = Zval::new();
    z.set_long(node_mtime(cfg, row));
    ins(&mut ht, "mtime", z)?;
    let mut z = Zval::new();
    z.set_long(row.generation);
    ins(&mut ht, "generation", z)?;
    ins(&mut ht, "langs", strings_to_zval(&langs)?)?;
    let mut z = Zval::new();
    z.set_long(inbound.len() as i64);
    ins(&mut ht, "is_link_target_of", z)?;
    let mut out = Zval::new();
    out.set_hashtable(ht);
    Ok(out)
}

// ---------------------------------------------------------------------------
// find/count machinery
// ---------------------------------------------------------------------------

struct Criteria {
    father: Option<String>,
    lineage: Option<String>,
    in_: Option<String>,
    name_prefix: Option<String>,
    where_: Vec<(String, Value)>,
}

fn parse_criteria(ht: Option<&ZendHashTable>) -> Result<Criteria, DbError> {
    let r = Reader::new(
        ht,
        &["father", "lineage", "in", "where", "name_prefix"],
        "criteria",
    )?;
    let mut where_ = Vec::new();
    if let Some(z) = r.raw("where") {
        let wht = z
            .array()
            .ok_or_else(|| DbError::BadArgs("criteria key 'where' must be an array".into()))?;
        for (k, v) in wht.iter() {
            let val = zval_to_json(v)?;
            if matches!(val, Value::Array(_) | Value::Object(_)) {
                return Err(DbError::BadArgs(
                    "'where' values must be scalars (v1 supports equality only)".into(),
                ));
            }
            where_.push((key_to_string(&k), val));
        }
    }
    Ok(Criteria {
        father: r.str_opt("father")?,
        lineage: r.str_opt("lineage")?,
        in_: r.str_opt("in")?,
        name_prefix: r.str_opt("name_prefix")?,
        where_,
    })
}

fn json_value_at<'v>(doc: &'v Value, path: &str) -> Option<&'v Value> {
    let mut cur = doc;
    for seg in path.split('.') {
        cur = match cur {
            Value::Object(map) => map.get(seg)?,
            Value::Array(items) => items.get(seg.parse::<usize>().ok()?)?,
            _ => return None,
        };
    }
    Some(cur)
}

fn json_eq(a: &Value, b: &Value) -> bool {
    match (a.as_f64(), b.as_f64()) {
        (Some(x), Some(y)) => x == y,
        _ => a == b,
    }
}

fn cmp_json(a: Option<&Value>, b: Option<&Value>) -> std::cmp::Ordering {
    use std::cmp::Ordering;
    fn rank(v: Option<&Value>) -> u8 {
        match v {
            None => 0,
            Some(Value::Null) => 1,
            Some(Value::Bool(_)) => 2,
            Some(Value::Number(_)) => 3,
            Some(Value::String(_)) => 4,
            Some(_) => 5,
        }
    }
    match (a, b) {
        (Some(Value::Number(x)), Some(Value::Number(y))) => x
            .as_f64()
            .partial_cmp(&y.as_f64())
            .unwrap_or(Ordering::Equal),
        (Some(Value::String(x)), Some(Value::String(y))) => x.cmp(y),
        (Some(Value::Bool(x)), Some(Value::Bool(y))) => x.cmp(y),
        _ => rank(a).cmp(&rank(b)),
    }
}

/// Every node name, sorted. SHM: slot iteration; fallback: walk snapshot.
fn all_names(cfg: &Config) -> Vec<String> {
    if let Some(seg) = seg_current(cfg) {
        let mut v = Vec::new();
        seg.for_each_live(|rec| v.push(rec.name().to_string()));
        v.sort();
        return v;
    }
    cache::snap_all_names(cfg)
}

/// Names whose node dir lives strictly under `base_row`'s dir, sorted.
fn names_under(cfg: &Config, base_row: &NodeRow) -> Vec<String> {
    if let Some(seg) = seg_current(cfg) {
        let base_rel = Path::new(&base_row.path)
            .strip_prefix(&cfg.root)
            .map(|p| p.to_string_lossy().to_string())
            .unwrap_or_default();
        let prefix = format!("{base_rel}/");
        let mut v = Vec::new();
        seg.for_each_live(|rec| {
            if rec.rel_path().starts_with(&prefix) {
                v.push(rec.name().to_string());
            }
        });
        v.sort();
        return v;
    }
    cache::snap_names_under(cfg, Path::new(&base_row.path))
}

fn names_by_prefix(cfg: &Config, prefix: &str) -> Vec<String> {
    if let Some(seg) = seg_current(cfg) {
        let mut v = Vec::new();
        seg.for_each_live(|rec| {
            if rec.name().starts_with(prefix) {
                v.push(rec.name().to_string());
            }
        });
        v.sort();
        return v;
    }
    cache::snap_all_names(cfg)
        .into_iter()
        .filter(|n| n.starts_with(prefix))
        .collect()
}

fn find_candidates(cfg: &Config, crit: &Criteria) -> Result<Vec<String>, DbError> {
    let mut sets: Vec<Vec<String>> = Vec::new();
    if let Some(f) = &crit.father {
        sets.push(children_impl(cfg, f, ChildType::All, true)?);
    }
    if let Some(i) = &crit.in_ {
        sets.push(children_impl(cfg, i, ChildType::All, true)?);
    }
    if let Some(l) = &crit.lineage {
        match resolve_node(cfg, l)? {
            Some(row) => sets.push(names_under(cfg, &row)),
            None => return Ok(Vec::new()),
        }
    }
    if let Some(p) = &crit.name_prefix {
        sets.push(names_by_prefix(cfg, p));
    }
    let names = match sets.len() {
        0 => all_names(cfg),
        1 => sets.pop().expect("one set"),
        _ => {
            let mut iter = sets.into_iter();
            let mut acc: HashSet<String> = iter.next().expect("sets").into_iter().collect();
            for s in iter {
                let s: HashSet<String> = s.into_iter().collect();
                acc.retain(|n| s.contains(n));
            }
            let mut v: Vec<String> = acc.into_iter().collect();
            v.sort();
            v
        }
    };
    Ok(names)
}

fn find_impl(
    cfg: &Config,
    criteria: Option<&ZendHashTable>,
    opts: Option<&ZendHashTable>,
) -> Result<Zval, DbError> {
    let crit = parse_criteria(criteria)?;
    let r = Reader::new(
        opts,
        &["return", "order_by", "order", "limit", "offset", "lang"],
        "opts",
    )?;
    let ret_mode = r.str_opt("return")?.unwrap_or_else(|| "names".into());
    if !["names", "data", "meta"].contains(&ret_mode.as_str()) {
        return Err(DbError::BadArgs(format!("invalid return mode '{ret_mode}'")));
    }
    let order_by = r.str_opt("order_by")?.unwrap_or_else(|| "name".into());
    let order = r.str_opt("order")?.unwrap_or_else(|| "asc".into());
    if !["asc", "desc"].contains(&order.as_str()) {
        return Err(DbError::BadArgs(format!("invalid order '{order}'")));
    }
    let limit = r.long_opt("limit")?;
    let offset = r.long_opt("offset")?.unwrap_or(0).max(0) as usize;
    let lang = normalize_lang(r.str_opt("lang")?)?;

    let candidates = find_candidates(cfg, &crit)?;

    // Filter by 'where' (loads documents; missing doc never matches).
    let mut names: Vec<String> = Vec::new();
    for name in candidates {
        if crit.where_.is_empty() {
            names.push(name);
            continue;
        }
        let Some(row) = resolve_node(cfg, &name)? else { continue };
        let Some(doc) = load_doc(cfg, &row, &lang)? else { continue };
        let matches = crit.where_.iter().all(|(path, expected)| {
            json_value_at(&doc, path).map(|v| json_eq(v, expected)) == Some(true)
        });
        if matches {
            names.push(name);
        }
    }

    // Ordering.
    if order_by == "name" {
        names.sort();
    } else if order_by == "mtime" {
        let mut keyed: Vec<(i64, String)> = Vec::new();
        for n in names {
            let m = resolve_node(cfg, &n)?.map(|r| node_mtime(cfg, &r)).unwrap_or(0);
            keyed.push((m, n));
        }
        keyed.sort_by(|a, b| a.0.cmp(&b.0).then_with(|| a.1.cmp(&b.1)));
        names = keyed.into_iter().map(|(_, n)| n).collect();
    } else if let Some(jpath) = order_by.strip_prefix("json:") {
        let mut keyed: Vec<(Option<Value>, String)> = Vec::new();
        for n in names {
            let val = match resolve_node(cfg, &n)? {
                Some(row) => load_doc(cfg, &row, &lang)?
                    .and_then(|d| json_value_at(&d, jpath).cloned()),
                None => None,
            };
            keyed.push((val, n));
        }
        keyed.sort_by(|a, b| cmp_json(a.0.as_ref(), b.0.as_ref()).then_with(|| a.1.cmp(&b.1)));
        names = keyed.into_iter().map(|(_, n)| n).collect();
    } else {
        return Err(DbError::BadArgs(format!("invalid order_by '{order_by}'")));
    }
    if order == "desc" {
        names.reverse();
    }

    // Slice.
    let names: Vec<String> = names
        .into_iter()
        .skip(offset)
        .take(limit.map(|l| l.max(0) as usize).unwrap_or(usize::MAX))
        .collect();

    // Shape the return value.
    match ret_mode.as_str() {
        "names" => strings_to_zval(&names),
        "data" => {
            let mut ht = ZendHashTable::new();
            for n in &names {
                let v = match resolve_node(cfg, n)? {
                    Some(row) => match load_doc(cfg, &row, &lang)? {
                        Some(doc) => json_to_zval(&doc)?,
                        None => null_zval(),
                    },
                    None => null_zval(),
                };
                ht.insert(ArrayKey::from(n.clone()), v)
                    .map_err(|e| DbError::Io(format!("array conversion: {e}")))?;
            }
            let mut z = Zval::new();
            z.set_hashtable(ht);
            Ok(z)
        }
        _ => {
            let mut ht = ZendHashTable::new();
            for n in &names {
                let v = match resolve_node(cfg, n)? {
                    Some(row) => meta_zval(cfg, &row)?,
                    None => null_zval(),
                };
                ht.insert(ArrayKey::from(n.clone()), v)
                    .map_err(|e| DbError::Io(format!("array conversion: {e}")))?;
            }
            let mut z = Zval::new();
            z.set_hashtable(ht);
            Ok(z)
        }
    }
}

// ---------------------------------------------------------------------------
// PHP class QuantaDb (contract §3) — every operation is a static method.
// ---------------------------------------------------------------------------

#[php_class]
#[php(name = "QuantaDb")]
#[derive(Default)]
pub struct QuantaDb;

/// Returns true when the caller asked for 'error' behavior on link/unlink.
/// Free helper (not a PHP method) shared by link()/unlink() below.
fn link_flag(opts: Option<&ZendHashTable>, key: &'static str) -> Result<bool, DbError> {
    let r = Reader::new(opts, &["if_exists", "if_not_exists"], "opts")?;
    match r.str_opt(key)?.as_deref() {
        None | Some("ignore") => Ok(false),
        Some("error") => Ok(true),
        Some(other) => Err(DbError::BadArgs(format!("invalid {key} value '{other}'"))),
    }
}

#[php_impl]
impl QuantaDb {

pub fn get(name: String, lang: Option<String>) -> PhpResult<Zval> {
    let cfg = config()?;
    let lang = normalize_lang(lang)?;
    let Some(row) = resolve_node(cfg, &name)? else {
        return Ok(null_zval());
    };
    match load_doc(cfg, &row, &lang)? {
        Some(doc) => Ok(json_to_zval(&doc)?),
        None => Ok(null_zval()),
    }
}

/// The raw JSON document as a string (SHM bytes verbatim), or null. Lets hot
/// call sites pair a zero-syscall read with PHP's native json_decode instead
/// of paying the Rust-side array construction. Contract 1.1 addition.
pub fn get_raw(name: String, lang: Option<String>) -> PhpResult<Option<String>> {
    let cfg = config()?;
    let lang = normalize_lang(lang)?;
    let Some(row) = resolve_node(cfg, &name)? else {
        return Ok(None);
    };
    Ok(load_raw(cfg, &row, &lang)?)
}

pub fn path(name: String) -> PhpResult<Option<String>> {
    let cfg = config()?;
    Ok(resolve_node(cfg, &name)?.map(|r| r.path))
}

pub fn exists(name: String) -> PhpResult<bool> {
    let cfg = config()?;
    Ok(resolve_node(cfg, &name)?.is_some())
}

pub fn meta(name: String) -> PhpResult<Zval> {
    let cfg = config()?;
    match resolve_node(cfg, &name)? {
        Some(row) => Ok(meta_zval(cfg, &row)?),
        None => Ok(null_zval()),
    }
}

pub fn children(father: String, opts: Option<&ZendHashTable>) -> PhpResult<Vec<String>> {
    let cfg = config()?;
    metrics::record_children();
    let r = Reader::new(opts, &["type", "include_hidden"], "opts")?;
    let ctype = match r.str_opt("type")?.as_deref() {
        None | Some("all") => ChildType::All,
        Some("dirs") => ChildType::Dirs,
        Some("links") => ChildType::Links,
        Some(other) => {
            return Err(DbError::BadArgs(format!("invalid children type '{other}'")).into())
        }
    };
    let include_hidden = r
        .raw("include_hidden")
        .map(|z| z.bool().unwrap_or(false))
        .unwrap_or(false);
    Ok(children_impl(cfg, &father, ctype, include_hidden)?)
}

pub fn links(target: String) -> PhpResult<Vec<String>> {
    let cfg = config()?;
    metrics::record_links();
    ensure_valid_name(&target)?;
    Ok(links_of(cfg, &target)?)
}

pub fn find(
    criteria: Option<&ZendHashTable>,
    opts: Option<&ZendHashTable>,
) -> PhpResult<Zval> {
    let cfg = config()?;
    metrics::record_find();
    Ok(find_impl(cfg, criteria, opts)?)
}

pub fn count(criteria: Option<&ZendHashTable>) -> PhpResult<i64> {
    let cfg = config()?;
    metrics::record_count();
    let result = find_impl(cfg, criteria, None)?;
    let n = result.array().map(|ht| ht.len()).unwrap_or(0);
    Ok(n as i64)
}

pub fn put(
    name: String,
    data: &ZendHashTable,
    opts: Option<&ZendHashTable>,
) -> PhpResult<bool> {
    let cfg = config()?;
    ensure_valid_name(&name)?;
    let r = Reader::new(opts, &["lang", "father"], "opts")?;
    let lang = normalize_lang(r.str_opt("lang")?)?;
    let father = r.str_opt("father")?;
    let value = table_to_json(data)?;
    let _lk = lock::acquire(cfg, &name)?;
    put_inner(cfg, &name, &value, &lang, father.as_deref())?;
    Ok(true)
}

pub fn update(
    name: String,
    callback: ZendCallable,
    opts: Option<&ZendHashTable>,
) -> PhpResult<Zval> {
    let cfg = config()?;
    ensure_valid_name(&name)?;
    let r = Reader::new(opts, &["lang"], "opts")?;
    let lang = normalize_lang(r.str_opt("lang")?)?;

    let _lk = lock::acquire(cfg, &name)?;
    let row = resolve_node(cfg, &name)?;
    let current = match &row {
        Some(row) => match load_doc(cfg, row, &lang)? {
            Some(doc) => json_to_zval(&doc)?,
            None => null_zval(),
        },
        None => null_zval(),
    };
    let ret = callback
        .try_call(vec![&current])
        .map_err(|e| DbError::Io(format!("update callback failed: {e:?}")))?;
    if ret.is_null() {
        return Ok(null_zval());
    }
    let ht = ret.array().ok_or_else(|| {
        DbError::BadArgs("update callback must return an array or null".to_string())
    })?;
    let value = table_to_json(ht)?;
    if row.is_none() {
        return Err(DbError::BadArgs(format!(
            "node '{name}' does not exist; create it with QuantaDb::put + opts['father']"
        ))
        .into());
    }
    put_inner(cfg, &name, &value, &lang, None)?;
    Ok(ret)
}

pub fn delete(name: String) -> PhpResult<bool> {
    let cfg = config()?;
    ensure_valid_name(&name)?;
    let _lk = lock::acquire(cfg, &name)?;
    let Some(row) = resolve_node(cfg, &name)? else {
        return Ok(false);
    };
    // Remove inbound symlinks so containers don't keep dangling members.
    let containers = links_of(cfg, &name)?;
    for container in &containers {
        if let Some(crow) = resolve_node(cfg, container)? {
            let link = Path::new(&crow.path).join(&name);
            if fs::symlink_metadata(&link)
                .map(|m| m.file_type().is_symlink())
                .unwrap_or(false)
            {
                let _ = fs::remove_file(&link);
            }
        }
    }
    store::move_to_trash(cfg, Path::new(&row.path))?;
    notify_daemon(cfg, ipc::msg_delete(&name));
    cache::invalidate(&name);
    cache::snap_remove(&name);
    metrics::deleted();
    Ok(true)
}

pub fn link(
    target: String,
    container: String,
    opts: Option<&ZendHashTable>,
) -> PhpResult<bool> {
    let cfg = config()?;
    metrics::record_link();
    let error_if_exists = link_flag(opts, "if_exists")?;
    let _lk = lock::acquire(cfg, &target)?;
    let t = resolve_node(cfg, &target)?
        .ok_or_else(|| DbError::BadArgs(format!("target node '{target}' not found")))?;
    let c = resolve_node(cfg, &container)?
        .ok_or_else(|| DbError::BadArgs(format!("container node '{container}' not found")))?;
    let link = Path::new(&c.path).join(&target);
    match std::os::unix::fs::symlink(&t.path, &link) {
        Ok(()) => {}
        Err(e) if e.kind() == std::io::ErrorKind::AlreadyExists => {
            if error_if_exists {
                return Err(DbError::Exists(format!(
                    "'{target}' is already linked in '{container}'"
                ))
                .into());
            }
        }
        Err(e) => return Err(DbError::from(e).into()),
    }
    notify_daemon(cfg, ipc::msg_link(&container, &target));
    cache::snap_link_set(&container, &target, true);
    Ok(true)
}

pub fn unlink(
    target: String,
    container: String,
    opts: Option<&ZendHashTable>,
) -> PhpResult<bool> {
    let cfg = config()?;
    metrics::record_unlink();
    let error_if_missing = link_flag(opts, "if_not_exists")?;
    ensure_valid_name(&target)?;
    let _lk = lock::acquire(cfg, &target)?;
    let removed = match resolve_node(cfg, &container)? {
        Some(c) => {
            let link = Path::new(&c.path).join(&target);
            if fs::symlink_metadata(&link)
                .map(|m| m.file_type().is_symlink())
                .unwrap_or(false)
            {
                fs::remove_file(&link).map_err(DbError::from)?;
                true
            } else {
                false
            }
        }
        None => false,
    };
    if !removed && error_if_missing {
        return Err(DbError::Io(format!(
            "'{target}' is not linked in '{container}'"
        ))
        .into());
    }
    notify_daemon(cfg, ipc::msg_unlink(&container, &target));
    cache::snap_link_set(&container, &target, false);
    Ok(removed)
}

pub fn relink(
    target: String,
    from_container: String,
    to_container: String,
) -> PhpResult<bool> {
    let cfg = config()?;
    ensure_valid_name(&target)?;
    let _lk = lock::acquire(cfg, &target)?;
    let t = resolve_node(cfg, &target)?
        .ok_or_else(|| DbError::BadArgs(format!("target node '{target}' not found")))?;
    let to = resolve_node(cfg, &to_container)?.ok_or_else(|| {
        DbError::BadArgs(format!("container node '{to_container}' not found"))
    })?;
    let new_link = Path::new(&to.path).join(&target);

    let old_link = resolve_node(cfg, &from_container)?
        .map(|c| Path::new(&c.path).join(&target))
        .filter(|p| {
            fs::symlink_metadata(p)
                .map(|m| m.file_type().is_symlink())
                .unwrap_or(false)
        });

    match old_link {
        // rename() moves the symlink atomically: readers never observe the
        // target in zero or two containers (contract §3 relink).
        Some(old) => fs::rename(&old, &new_link).map_err(DbError::from)?,
        None => match std::os::unix::fs::symlink(&t.path, &new_link) {
            Ok(()) => {}
            Err(e) if e.kind() == std::io::ErrorKind::AlreadyExists => {}
            Err(e) => return Err(DbError::from(e).into()),
        },
    }
    notify_daemon(cfg, ipc::msg_relink(&target, &from_container, &to_container));
    cache::snap_link_set(&from_container, &target, false);
    cache::snap_link_set(&to_container, &target, true);
    Ok(true)
}

pub fn reindex(subtree: Option<String>) -> PhpResult<Zval> {
    let cfg = config()?;
    let started = Instant::now();
    let base: PathBuf = match &subtree {
        // A targeted reindex must find its base directory on disk even when
        // the daemon has not yet caught up with a just-created dir, so resolve
        // via the filesystem rather than resolve_node's authoritative
        // short-circuit (which would report the fresh dir as a definitive miss).
        Some(s) => resolve_subtree_dir(cfg, s)?
            .ok_or_else(|| DbError::BadArgs(format!("subtree node '{s}' not found")))?,
        None => cfg.root.clone(),
    };

    // Daemon first: it owns the derived data, so IT must rebuild. A rebuild
    // walks the tree and re-reads every doc — give it a generous budget.
    let (nodes, links) = {
        let msg = ipc::msg_reindex(subtree.as_deref());
        match ipc::request(&cfg.socket_path, Duration::from_secs(60), &msg) {
            Ok(ack) if ipc::ack_ok(&ack) => (
                ipc::ack_u64(&ack, "nodes").unwrap_or(0) as usize,
                ipc::ack_u64(&ack, "links").unwrap_or(0) as usize,
            ),
            _ => {
                // No daemon: fallback derived data is just the per-process
                // caches — count from a fresh walk (files stay the truth).
                let (n, l) = model::walk_dedup(cfg, &base);
                (n.len(), l.len())
            }
        }
    };
    // The tree was just re-walked; drop stale local verdicts so freshly
    // (re)indexed names resolve without waiting out TTLs.
    cache::neg_clear();
    cache::snap_invalidate();
    cache::clear_docs();

    let mut ht = ZendHashTable::new();
    let mut z = Zval::new();
    z.set_long(nodes as i64);
    ht.insert(ArrayKey::Str("nodes"), z)
        .map_err(|e| DbError::Io(format!("array conversion: {e}")))?;
    let mut z = Zval::new();
    z.set_long(links as i64);
    ht.insert(ArrayKey::Str("links"), z)
        .map_err(|e| DbError::Io(format!("array conversion: {e}")))?;
    let mut z = Zval::new();
    z.set_double(started.elapsed().as_secs_f64());
    ht.insert(ArrayKey::Str("seconds"), z)
        .map_err(|e| DbError::Io(format!("array conversion: {e}")))?;
    let mut out = Zval::new();
    out.set_hashtable(ht);
    Ok(out)
}

pub fn stats() -> PhpResult<Zval> {
    let cfg = config()?;
    let seg = seg_current(cfg);
    let (nodes, links, mode) = match &seg {
        Some(seg) => {
            let h = seg.header();
            (
                h.node_count.load(std::sync::atomic::Ordering::Relaxed) as i64,
                h.link_count.load(std::sync::atomic::Ordering::Relaxed) as i64,
                "shm",
            )
        }
        None => {
            let (n, l) = model::walk_dedup(cfg, &cfg.root);
            (n.len() as i64, l.len() as i64, "fallback")
        }
    };
    let mut ht = ZendHashTable::new();
    let ins_str = |ht: &mut ZendHashTable, k: &'static str, v: &str| -> Result<(), DbError> {
        let mut z = Zval::new();
        z.set_string(v, false).map_err(|e| DbError::Io(e.to_string()))?;
        ht.insert(ArrayKey::Str(k), z)
            .map_err(|e| DbError::Io(format!("array conversion: {e}")))
    };
    ins_str(&mut ht, "implementation", "ext")?;
    ins_str(&mut ht, "contract", CONTRACT_VERSION)?;
    ins_str(&mut ht, "version", env!("CARGO_PKG_VERSION"))?;
    ins_str(&mut ht, "root", &cfg.root.to_string_lossy())?;
    ins_str(&mut ht, "mode", mode)?;
    ins_str(&mut ht, "shm_dir", &cfg.shm_dir.to_string_lossy())?;
    ins_str(&mut ht, "socket_path", &cfg.socket_path.to_string_lossy())?;
    ins_str(
        &mut ht,
        "verify_reads",
        match cfg.verify_reads {
            VerifyReads::Always => "always",
            VerifyReads::Never => "never",
        },
    )?;
    let mut ins_long = |k: &'static str, v: i64| -> Result<(), DbError> {
        let mut z = Zval::new();
        z.set_long(v);
        ht.insert(ArrayKey::Str(k), z)
            .map_err(|e| DbError::Io(format!("array conversion: {e}")))
    };
    ins_long("nodes", nodes)?;
    ins_long("links", links)?;
    if let Some(seg) = &seg {
        let h = seg.header();
        use std::sync::atomic::Ordering::Relaxed;
        ins_long("epoch", seg.epoch as i64)?;
        ins_long("shm_bytes", h.arena_next.load(Relaxed) as i64)?;
        ins_long("shm_size", h.seg_size as i64)?;
        ins_long("shm_dead_bytes", h.dead_bytes.load(Relaxed) as i64)?;
        ins_long("doc_bytes", h.doc_bytes.load(Relaxed) as i64)?;
    }
    // Live counters from the shared-memory arena (absent when metrics are off).
    if let Some(s) = metrics::snapshot() {
        let mut ins_cnt = |k: &'static str, v: u64| -> Result<(), DbError> {
            let mut z = Zval::new();
            z.set_long(v as i64);
            ht.insert(ArrayKey::Str(k), z)
                .map_err(|e| DbError::Io(format!("array conversion: {e}")))
        };
        ins_cnt("reads", s.reads)?;
        ins_cnt("read_ns", s.read_ns)?;
        ins_cnt("cache_hits", s.cache_hits)?;
        ins_cnt("cache_misses", s.cache_misses)?;
        ins_cnt("index_serves", s.index_serves)?;
        ins_cnt("file_reads", s.file_reads)?;
        ins_cnt("bytes_read", s.bytes_read)?;
        ins_cnt("fs_heals", s.fs_heals)?;
        ins_cnt("node_misses", s.node_misses)?;
        ins_cnt("neg_hits", s.neg_hits)?;
        ins_cnt("writes", s.writes)?;
        ins_cnt("write_ns", s.write_ns)?;
        ins_cnt("bytes_written", s.bytes_written)?;
        ins_cnt("deletes", s.deletes)?;
        ins_cnt("lock_acquires", s.lock_acquires)?;
        ins_cnt("lock_wait_ns", s.lock_wait_ns)?;
        ins_cnt("lock_timeouts", s.lock_timeouts)?;
        ins_cnt("io_errors", s.io_errors)?;
        ins_cnt("corrupt_json", s.corrupt_json)?;
        ins_cnt("authoritative_misses", s.authoritative_misses)?;
        ins_cnt("watch_coherent", s.watch_coherent)?;
        ins_cnt("watch_heartbeat_unix", s.watch_heartbeat_unix)?;
        ins_cnt("watch_epoch", s.watch_epoch)?;
        ins_cnt("watch_events", s.watch_events)?;
        ins_cnt("watch_resyncs", s.watch_resyncs)?;
        ins_cnt("data_epoch", s.data_epoch)?;
        ins_cnt("daemon_pid", s.daemon_pid)?;
        ins_cnt("uds_notifies", s.uds_notifies)?;
        ins_cnt("uds_failures", s.uds_failures)?;
        ins_cnt("shm_hits", s.shm_hits)?;
        ins_cnt("shm_remaps", s.shm_remaps)?;
        ins_cnt("shm_invalid", s.shm_invalid)?;
        ins_cnt("fallback_reads", s.fallback_reads)?;
        ins_cnt("children_ops", s.children_ops)?;
        ins_cnt("find_ops", s.find_ops)?;
        ins_cnt("count_ops", s.count_ops)?;
        ins_cnt("links_ops", s.links_ops)?;
        ins_cnt("link_ops", s.link_ops)?;
        ins_cnt("unlink_ops", s.unlink_ops)?;
        ins_cnt("read_ns_max", s.read_ns_max)?;
        ins_cnt("write_ns_max", s.write_ns_max)?;
    }
    let mut out = Zval::new();
    out.set_hashtable(ht);
    Ok(out)
}

/// True while a healthy qdbd daemon is serving the shared-memory segment
/// (fresh heartbeat + coherent flag). The Quanta shim uses this to decide
/// whether a null lookup is a definitive "absent" (skip the legacy `find`)
/// or just "the fast path doesn't know".
pub fn coherent() -> bool {
    // Ensure the metrics arena is mapped (config() runs metrics::init on first
    // use). Callers may probe coherence before any other operation.
    let _ = config();
    metrics::index_coherent()
}

pub fn version() -> String {
    format!("ext/{CONTRACT_VERSION}")
}

} // impl QuantaDb

// ---------------------------------------------------------------------------
// Module registration
// ---------------------------------------------------------------------------

fn startup(_ty: i32, module_number: i32) -> i32 {
    let entries = vec![
        IniEntryDef::new(
            "quanta_db.root".into(),
            String::new(),
            &IniEntryPermission::All,
        ),
        IniEntryDef::new(
            "quanta_db.shm_dir".into(),
            String::new(),
            &IniEntryPermission::All,
        ),
        IniEntryDef::new(
            "quanta_db.socket_path".into(),
            String::new(),
            &IniEntryPermission::All,
        ),
        IniEntryDef::new(
            "quanta_db.lock_dir".into(),
            String::new(),
            &IniEntryPermission::All,
        ),
        IniEntryDef::new(
            "quanta_db.trashbin_dir".into(),
            String::new(),
            &IniEntryPermission::All,
        ),
        IniEntryDef::new(
            "quanta_db.lock_timeout_ms".into(),
            "5000".into(),
            &IniEntryPermission::All,
        ),
        IniEntryDef::new(
            "quanta_db.write_ack_timeout_ms".into(),
            "250".into(),
            &IniEntryPermission::All,
        ),
        IniEntryDef::new(
            "quanta_db.shm_size_mb".into(),
            "64".into(),
            &IniEntryPermission::All,
        ),
        IniEntryDef::new(
            "quanta_db.verify_reads".into(),
            "always".into(),
            &IniEntryPermission::All,
        ),
        IniEntryDef::new(
            "quanta_db.neg_cache_ms".into(),
            "30000".into(),
            &IniEntryPermission::All,
        ),
        // Empty default so an env override (QUANTA_DB_METRICS) is honored; an
        // empty/unset value means enabled (see config::build).
        IniEntryDef::new(
            "quanta_db.metrics".into(),
            String::new(),
            &IniEntryPermission::All,
        ),
        IniEntryDef::new(
            "quanta_db.metrics_path".into(),
            String::new(),
            &IniEntryPermission::All,
        ),
    ];
    IniEntryDef::register(entries, module_number);
    0
}

#[php_module]
#[php(startup = startup)]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .class::<QuantaDbException>()
        .class::<QuantaDb>()
}
