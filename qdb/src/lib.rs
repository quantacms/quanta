//! quanta_db — PHP extension implementing the qdb API contract v1.3
//! (qdb/docs/api-contract.md). The JSON files stay the source of truth;
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
mod image;
mod ipc;
mod lock;
mod metrics;
mod model;
mod paths;
mod php_abi;
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
use ext_php_rs::convert::IntoZval;
use ext_php_rs::types::{ArrayKey, ZendHashTable, ZendObject, ZendStr, Zval};
use ext_php_rs::zend::{ce, ClassEntry, IniEntryDef};
use serde_json::Value;

use config::{Config, VerifyReads};
use error::DbError;
use shm::Lookup;

const CONTRACT_VERSION: &str = "1.3";

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

/// Materialize a decoded document exactly as `json_decode($raw)` does: JSON
/// objects become `stdClass`, JSON arrays become PHP lists.
///
/// This is NOT interchangeable with [`json_to_zval`], and casting that
/// function's result with `(object)` is not a substitute: the cast only
/// converts the top level, so a nested `{"permissions":{"node_view":"..."}}`
/// would come back with `permissions` as an *array*, and Quanta reads it as
/// `$node->json->permissions->{$permission}` (Node::loadPermissions,
/// access.hook.inc). The shapes must match all the way down.
///
/// `json_encode()` cannot tell the two apart — a string-keyed PHP array encodes
/// as a JSON object — so shape parity has to be asserted with var_export()
/// or a recursive walk, never a JSON round-trip.
/// The document root, cast the way `(object) json_decode($raw)` casts it.
///
/// Only the ROOT differs from [`json_to_object_zval`]: PHP's `(object)` cast
/// turns a top-level JSON array into a stdClass with "0", "1", … properties and
/// a top-level scalar into one with a `scalar` property, while anything nested
/// keeps its natural shape. Real node documents are always objects, but
/// `Node::loadJSON` assigns this straight to `$node->json` where the legacy
/// read assigned `(object) json_decode(...)`, so the odd roots have to agree
/// too or the extension is not a drop-in.
fn json_root_to_object_zval(v: &Value) -> Result<Zval, DbError> {
    match v {
        Value::Object(_) => json_to_object_zval(v),
        Value::Null => {
            // (object) null === new stdClass
            let mut z = Zval::new();
            ZendObject::new_stdclass()
                .set_zval(&mut z, false)
                .map_err(|e| DbError::Io(format!("object conversion: {e}")))?;
            Ok(z)
        }
        Value::Array(items) => {
            let mut obj = ZendObject::new_stdclass();
            for (i, item) in items.iter().enumerate() {
                obj.set_property(&i.to_string(), json_to_object_zval(item)?)
                    .map_err(|e| DbError::Io(format!("object property '{i}': {e}")))?;
            }
            let mut z = Zval::new();
            obj.set_zval(&mut z, false)
                .map_err(|e| DbError::Io(format!("object conversion: {e}")))?;
            Ok(z)
        }
        scalar => {
            let mut obj = ZendObject::new_stdclass();
            obj.set_property("scalar", json_to_object_zval(scalar)?)
                .map_err(|e| DbError::Io(format!("object property 'scalar': {e}")))?;
            let mut z = Zval::new();
            obj.set_zval(&mut z, false)
                .map_err(|e| DbError::Io(format!("object conversion: {e}")))?;
            Ok(z)
        }
    }
}

fn json_to_object_zval(v: &Value) -> Result<Zval, DbError> {
    let mut z = Zval::new();
    match v {
        Value::Object(map) => {
            let mut obj = ZendObject::new_stdclass();
            for (k, val) in map {
                obj.set_property(k, json_to_object_zval(val)?)
                    .map_err(|e| DbError::Io(format!("object property '{k}': {e}")))?;
            }
            obj.set_zval(&mut z, false)
                .map_err(|e| DbError::Io(format!("object conversion: {e}")))?;
        }
        Value::Array(items) => {
            let mut ht = ZendHashTable::with_capacity(items.len() as u32);
            for item in items {
                ht.push(json_to_object_zval(item)?)
                    .map_err(|e| DbError::Io(format!("array conversion: {e}")))?;
            }
            z.set_hashtable(ht);
        }
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
// Pre-decoded image materialization (contract §3 `getObject`)
// ---------------------------------------------------------------------------

/// Set at MINIT: does the PHP we are loaded into match the ABI the image
/// assumes? False disables the image path entirely (reads still work, they just
/// parse the raw bytes). Never fails MINIT — the extension must keep serving.
static IMAGE_ABI_OK: std::sync::atomic::AtomicBool = std::sync::atomic::AtomicBool::new(false);

/// How many distinct segment epochs one request may pin. `qdbd` compacts
/// whenever dead bytes pass a threshold, so a long write-heavy request can see
/// several flips; past this cap we stop handing out pointers into the mapping
/// and copy instead, so a compaction storm cannot pin unbounded memory.
const MAX_PINNED_EPOCHS: usize = 4;

thread_local! {
    /// Segment mappings that must stay alive because live PHP zvals point into
    /// them. Drained in `post_deactivate` — see `request_cleanup`.
    static PINNED: RefCell<Vec<Rc<shm::SegmentReader>>> = const { RefCell::new(Vec::new()) };

}

/// Keep `seg` mapped until the end of the request. Returns false when the pin
/// cap is reached, meaning the caller must copy rather than point.
fn pin_segment(seg: &Rc<shm::SegmentReader>) -> bool {
    PINNED.with(|p| {
        let mut v = p.borrow_mut();
        if v.iter().any(|s| Rc::ptr_eq(s, seg)) {
            return true;
        }
        if v.len() >= MAX_PINNED_EPOCHS {
            metrics::seg_pin_max();
            return false;
        }
        v.push(seg.clone());
        metrics::seg_pin();
        true
    })
}

/// Release everything held for the request. MUST run after the executor has
/// been shut down, i.e. from `post_deactivate` rather than RSHUTDOWN: module
/// RSHUTDOWN runs BEFORE `zend_deactivate()` destroys the object store, and
/// freeing the mapping there would leave `zend_hash_destroy` reading
/// `GC_FLAGS(key)` out of unmapped memory while tearing down objects that still
/// point into it.
fn request_cleanup() {
    PINNED.with(|p| p.borrow_mut().clear());
}

/// Canonical interned `zend_string` for an object key.
///
/// Keys must be strings PHP itself interned: `zend_std_read_property` validates
/// its inline property cache by POINTER identity against the compile-time
/// interned name, so handing it our own SHM-resident string would silently
/// defeat that cache on every `$node->json->title` in the codebase.
///
/// Deliberately NOT memoised by image offset. Offsets are image-relative, so
/// two different nodes' images collide on the same small offsets within one
/// epoch, and a memo keyed that way hands one node another node's property
/// names — silent data corruption, not a crash. PHP's interned table already
/// dedupes by content, which is the only key that is actually unique.
fn interned_key(bytes: &[u8]) -> Result<*mut ext_php_rs::ffi::zend_string, DbError> {
    let init = unsafe { ext_php_rs::ffi::zend_string_init_interned }
        .ok_or_else(|| DbError::Io("zend_string_init_interned unavailable".into()))?;
    // permanent = false: request-lifetime interning is enough, and it keeps
    // these out of the permanent table, which is never freed.
    let p = unsafe { init(bytes.as_ptr().cast(), bytes.len(), false) };
    if p.is_null() {
        return Err(DbError::Io("interning key failed".into()));
    }
    Ok(p)
}

/// Build a string zval from an image string entry.
///
/// Zero-copy: the image already holds a complete `zend_string` (header + bytes
/// + NUL, 8-aligned, non-zero precomputed hash, flagged interned), and a
/// `zend_string` contains no internal pointers — so it is valid at whatever
/// address this process mapped the segment at. Pointing a zval straight at it
/// costs no allocation and no copy, at any document size.
///
/// The mapping must outlive the zval, which `pin_segment` guarantees for the
/// rest of the request. When the pin cap is hit (or zero-copy is off), fall
/// back to copying the bytes into a request-owned string.
unsafe fn image_string_zval(bytes: &[u8], shm_ptr: Option<*mut u8>) -> Zval {
    let mut z = Zval::new();
    match shm_ptr {
        Some(p) => {
            // Interned strings are never refcounted and `zend_string_release`
            // on them is a no-op, so PHP will neither free nor mutate this.
            z.set_zend_string(unsafe {
                ext_php_rs::boxed::ZBox::from_raw(p.cast::<ext_php_rs::types::ZendStr>())
            });
        }
        None => {
            z.set_zend_string(ZendStr::new(bytes, false));
        }
    }
    z
}

/// Walk the image into zvals. `objects` selects `getObject` shape (JSON objects
/// become stdClass) over `get` shape (associative arrays).
fn materialize(
    img: &image::Image,
    base: Option<*mut u8>,
    off: u32,
    depth: u32,
    objects: bool,
) -> Result<Zval, DbError> {
    if depth > image::MAX_DEPTH {
        return Err(DbError::CorruptJson("image nested too deeply".into()));
    }
    let node = img
        .node(off)
        .ok_or_else(|| DbError::CorruptJson("invalid image node".into()))?;
    let mut z = Zval::new();
    match node {
        image::Node::Null => z.set_null(),
        image::Node::Bool(b) => z.set_bool(b),
        image::Node::Long(i) => z.set_long(i),
        image::Node::Double(d) => z.set_double(d),
        image::Node::Str { off: so, bytes } => {
            let ptr = base.map(|b| unsafe { b.add(so as usize) });
            z = unsafe { image_string_zval(bytes, ptr) };
        }
        image::Node::List { count, table } => {
            let mut ht = ZendHashTable::with_capacity(count);
            for i in 0..count {
                let eo = img
                    .list_elem(table, i)
                    .ok_or_else(|| DbError::CorruptJson("invalid image list".into()))?;
                ht.push(materialize(img, base, eo, depth + 1, objects)?)
                    .map_err(|e| DbError::Io(format!("array conversion: {e}")))?;
            }
            z.set_hashtable(ht);
        }
        image::Node::Map { count, table } => {
            // One presized table, keys canonically interned by PHP. Interning
            // matters beyond allocation: zend_std_read_property validates its
            // inline property cache by pointer identity against the
            // compile-time interned name, so a non-canonical key silently
            // forces a full hash lookup on every `$node->json->x` in the app.
            let mut ht = ZendHashTable::with_capacity(count);
            for i in 0..count {
                let (ko, vo) = img
                    .map_pair(table, i)
                    .ok_or_else(|| DbError::CorruptJson("invalid image map".into()))?;
                let kb = img
                    .string_bytes(ko)
                    .ok_or_else(|| DbError::CorruptJson("invalid image key".into()))?;
                let key = interned_key(kb)?;
                let val = materialize(img, base, vo, depth + 1, objects)?;
                // SAFETY: `key` is a live interned zend_string owned by the
                // engine's interned table; insert only borrows it.
                ht.insert(ArrayKey::ZendString(unsafe { &*key }), val)
                    .map_err(|e| DbError::Io(format!("array conversion: {e}")))?;
            }
            if objects {
                // stdClass declares no properties, so `properties` is exactly
                // the dynamic-property table — this is what the engine would
                // have built lazily in rebuild_object_properties().
                let obj = ZendObject::new_stdclass();
                let raw = obj.into_raw();
                unsafe {
                    (*raw).properties = ht.into_raw();
                    z.set_object(&mut *raw);
                    // set_object incremented the refcount; drop our own.
                    ext_php_rs::ffi::ext_php_rs_zend_object_release(raw);
                }
            } else {
                z.set_hashtable(ht);
            }
        }
    }
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
    seg_map(cfg)
}

/// The last segment the daemon published, mapped *without* asking whether the
/// daemon is still there — the source for the stale-read path below.
///
/// Coherence is a liveness signal about the DAEMON. The segment is a fact about
/// the DATA: a file whose header carries its own magic, layout version, epoch
/// and root hash, and whose every record is re-verified by full hash plus name
/// comparison on each probe. `SegmentReader::open` checks all of that with no
/// daemon involved, the daemon retires an epoch only once its successor is
/// published, and no exit path unlinks it. So when the daemon goes, its last
/// published segment is still here, still valid, and still mapped into this
/// process — and throwing it away costs a whole-tree walk per worker to
/// rediscover what it already says.
///
/// What the segment stops being when the daemon goes is AUTHORITATIVE, and only
/// about absence: nothing is maintaining it, so a name it does not hold may
/// have been created since. That is why `seg_stale` feeds one caller only
/// (`resolve_stale`), why that caller answers hits and never misses, and why it
/// confirms every hit against the filesystem before returning it.
fn seg_map(cfg: &Config) -> Option<Rc<shm::SegmentReader>> {
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

/// Root-relative path as the daemon names nodes over the socket. A path outside
/// the root cannot be made relative, so it is passed through whole and the
/// daemon rejects it — better than silently addressing the wrong node.
fn rel_of(cfg: &Config, path: &Path) -> String {
    path.strip_prefix(&cfg.root)
        .map(|p| p.to_string_lossy().to_string())
        .unwrap_or_else(|_| path.to_string_lossy().to_string())
}

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
    if let Some(row) = resolve_stale(cfg, name) {
        return Ok(Some(row));
    }
    resolve_fallback(cfg, name)
}

/// The last published segment when — and only when — it is no longer
/// authoritative. `seg_current` and this are mutually exclusive by construction,
/// so no lookup can be answered twice or from two sources at once.
fn seg_stale(cfg: &Config) -> Option<Rc<shm::SegmentReader>> {
    if metrics::index_coherent() {
        return None;
    }
    seg_map(cfg)
}

/// Resolve a name from the last published segment, positive answers only.
///
/// This is the whole of the degraded fast path. Without it, losing the daemon
/// costs every worker a full `walk_dedup` of the tree to answer its first
/// lookup, and another every time that snapshot ages out — the cost that turns
/// a dead daemon into an outage rather than a slowdown. The segment already
/// holds the answer, so the walk is spent rediscovering it.
///
/// Three properties make this safe, and all three are load-bearing:
///
/// 1. **It can only add answers.** Every negative outcome — a miss, a
///    tombstone, a record that fails validation, a hit the filesystem does not
///    confirm — returns `None` and falls through to `resolve_fallback`
///    unchanged. A stale segment can therefore turn a walk into a hit; it can
///    never turn a hit into a miss, and it never writes to the negative cache.
///    That asymmetry is what lets an arbitrarily old segment be consulted at
///    all: staleness costs a fallthrough, never a wrong absence.
///
/// 2. **Every hit is confirmed against the filesystem.** The segment is not
///    being maintained, so its path may name a directory that has since been
///    moved or deleted, and `is_dir` is what stands between that and a caller
///    holding a path to nothing. This is the same confirmation
///    `resolve_fallback` already makes on its own snapshot hits, for the same
///    reason and with the same one-`stat` cost — against a walk, free.
///
/// 3. **A confirmed path cannot belong to a different node.** In this database
///    a node's name IS its directory's basename, so the record keyed `name`
///    and a live directory at the path it names are the same node by
///    definition. Were the name to have moved, the confirmation in (2) would
///    have to pass at the OLD path, which requires a directory of that name to
///    exist there again — and that directory would be a node of that name.
///
/// What this deliberately does not do is answer absence, enumerate
/// (`find`/`links`/lineage still walk), or serve documents. Those need the
/// segment to be complete, not merely correct about what it holds.
fn resolve_stale(cfg: &Config, name: &str) -> Option<NodeRow> {
    let seg = seg_stale(cfg)?;
    let Lookup::Found(rec) = seg.lookup(name) else {
        return None;
    };
    if rec.is_tombstone() {
        return None;
    }
    let path = cfg.root.join(rec.rel_path());
    if !path.is_dir() {
        // Positive evidence that the tree moved under the segment. Counted
        // separately from a plain miss: a rising share here is the signal that
        // the segment has drifted far enough to be worth little, which is
        // exactly what an operator needs to see and cannot otherwise infer.
        metrics::stale_unconfirmed();
        return None;
    }
    metrics::stale_hit();
    Some(NodeRow {
        name: name.to_string(),
        path: path.to_string_lossy().to_string(),
        father: rec.father().map(str::to_string),
        generation: rec.generation as i64,
    })
}

fn resolve_fallback(cfg: &Config, name: &str) -> Result<Option<NodeRow>, DbError> {
    // Distinguish "the snapshot never knew this name" from "the snapshot knew
    // it but the path is stale". The second is positive evidence that the node
    // moved, which makes it the one case where a negative verdict must not be
    // cached — see below.
    let known_but_stale = match cache::snap_lookup(cfg, name) {
        Some(e) if e.path.is_dir() => return Ok(Some(row_from_snap(cfg, name, &e))),
        Some(_) => true,
        // Not in the snapshot at all — but a recent walk may still have held
        // it, which is the same kind of positive evidence as a stale path and
        // is treated the same way. Without this, one walk that raced an
        // external rename is enough to condemn a live node to the negative
        // cache: the name simply drops out of the snapshot, and every test
        // below that keys off "the snapshot knew it" stops firing.
        None => cache::snap_vanished(name),
    };
    // Snapshot miss or a stale path (external create/move/delete). Recently
    // proven absent? Skip the walk (TTL-bounded, contract §4.3 allows it).
    if !known_but_stale
        && cfg.neg_cache_ms > 0
        && cache::neg_fresh(name, Duration::from_millis(cfg.neg_cache_ms))
    {
        return Ok(None);
    }
    // Self-heal: one fresh walk, then answer from it. Repeat self-heals may be
    // coalesced onto the last walk ONLY for a name nothing has vouched for —
    // for anything `known_but_stale` this walk is the second, independent look
    // the negative-cache verdict below rests on, and skipping it would make
    // that verdict rest on the very walk that just failed the caller.
    cache::snap_rebuild(cfg, !known_but_stale);
    if let Some(e) = cache::snap_lookup_raw(name) {
        if e.path.is_dir() {
            metrics::fs_heal();
            return Ok(Some(row_from_snap(cfg, name, &e)));
        }
    }
    metrics::node_miss();
    // Never cache "absent" for a name the snapshot had at a now-stale path. The
    // rebuild above walks a tree that another process may be renaming inside,
    // so it can miss a node that genuinely exists — and caching that verdict
    // would blind this worker to the node for the whole TTL. A name the
    // snapshot never held has no such evidence behind it, so it still caches.
    if cfg.neg_cache_ms > 0 && !known_but_stale {
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

/// Validate one document image and, when zero-copy is on, pin the mapping it
/// lives in for the rest of the request.
///
/// **This is the only `image::Image::open` call site in the extension.** Every
/// image read — `get`, `getObject`, `load`, the `where` walk in `find` — funnels
/// through here precisely so that a change to how the image addresses its
/// strings is a change to this function and nothing else. Strings live in the
/// segment-wide table rather than inside the image, which is why both the
/// reader (`seg.bytes()`) and the zero-copy base (`seg.base_ptr()`) are the
/// whole mapping and not the image slice: `Node::Str.off` is a SEGMENT offset,
/// so measuring it from the image would point outside it.
///
/// `pin` asks for the zero-copy base pointer. Pass FALSE when the caller only
/// walks the image into owned data (`to_value`): pinning is capped per request,
/// and spending a pin on a read that copies anyway would starve the reads that
/// actually hand PHP a pointer into the mapping.
fn open_image<'a>(
    cfg: &Config,
    seg: &'a Rc<shm::SegmentReader>,
    img_bytes: &'a [u8],
    pin: bool,
) -> Option<(image::Image<'a>, Option<*mut u8>)> {
    let Some(img) = image::Image::open(img_bytes, seg.bytes()) else {
        metrics::img_invalid();
        return None;
    };
    // Zero-copy needs the mapping to outlive the zvals; if we cannot pin it
    // (cap reached) or it is disabled, materialize by copying instead.
    let base = if pin && cfg.zero_copy && pin_segment(seg) {
        Some(seg.base_ptr())
    } else {
        None
    };
    Some((img, base))
}

/// True when this process may read images at all. Split out because `load()`
/// applies the same gate from inside its own probe rather than through
/// [`with_shm_image`].
fn image_enabled(cfg: &Config) -> bool {
    use std::sync::atomic::Ordering as AtomicOrdering;
    cfg.image && IMAGE_ABI_OK.load(AtomicOrdering::Relaxed)
}

/// One document served straight from the pre-decoded image, with no JSON parse
/// at all. Returns `Ok(None)` when there is no usable image for this document
/// (images off, oversize, ABI mismatch, damaged image) so the caller can fall
/// back to the raw bytes + parse cache.
fn with_shm_image<T>(
    cfg: &Config,
    name: &str,
    lang: &str,
    build: impl FnOnce(&image::Image, Option<*mut u8>) -> Result<T, DbError>,
) -> Result<Option<ShmDoc<T>>, DbError> {
    if !image_enabled(cfg) {
        return Ok(None);
    }
    let Some(seg) = seg_current(cfg) else {
        return Ok(None);
    };
    let Lookup::Found(rec) = seg.lookup(name) else {
        return Ok(None);
    };
    if rec.is_tombstone() {
        return Ok(None);
    }
    let img_bytes = match rec.image(lang) {
        // A corrupt document has no image; let the raw path raise CORRUPT_JSON
        // so the error text and metrics stay in one place.
        Err(()) => return Ok(None),
        Ok(None) => {
            metrics::img_absent();
            return Ok(None);
        }
        Ok(Some(b)) => b,
    };
    let Some((img, base)) = open_image(cfg, &seg, img_bytes, true) else {
        return Ok(None);
    };
    // An image serve IS a segment serve — `img_serves` sub-classifies it as
    // "needed no parsing", the way `raw_writes` sub-classifies `writes`. Both
    // counters must move, or the served-from breakdown (index / file /
    // fallback) loses every read the image answers, which since v1.2 is most
    // of them: it reported 0% shm on a pod serving everything from shm.
    metrics::index_serve();
    metrics::img_serve();
    build(&img, base).map(|v| Some(ShmDoc::Served(v)))
}

/// Outcome of a single-probe document lookup in the segment.
enum ShmDoc<T> {
    /// Served from shared memory.
    Served(T),
    /// The segment is authoritative: this node has no such document.
    Absent,
    /// No usable segment (fallback mode, or a record that failed validation).
    /// The caller must resolve the node and read the file.
    Unavailable,
}

/// One hash probe, then hand the document's SHM bytes to `f`. The segment `Rc`
/// is held for the whole body, so the record view stays mapped throughout.
///
/// This is the single entry point for every SHM-backed read (`get`, `getRaw`,
/// `getObject`). Going through `resolve_node` first would probe twice and build
/// an absolute path `String` that a shared-memory read never looks at — the
/// path is only needed on the fallback path, so it is only built there.
fn with_shm_doc<T>(
    cfg: &Config,
    name: &str,
    lang: &str,
    f: impl FnOnce(&[u8], i64) -> Result<T, DbError>,
) -> Result<ShmDoc<T>, DbError> {
    let Some(seg) = seg_current(cfg) else {
        return Ok(ShmDoc::Unavailable);
    };
    match seg.lookup(name) {
        Lookup::Found(rec) if !rec.is_tombstone() => {
            metrics::shm_hit();
            match rec.doc(lang) {
                Err(()) => {
                    metrics::corrupt_json();
                    Err(DbError::CorruptJson(format!(
                        "invalid JSON in {}/{}",
                        rec.rel_path(),
                        store::doc_file(lang)
                    )))
                }
                Ok(shm::DocBytes::Absent) => Ok(ShmDoc::Absent),
                // The document exists, but the segment holds only its image
                // (layout v3). `Unavailable` is exactly the right answer: it
                // sends the caller to resolve the node and read the file, which
                // is the only byte-exact source there has ever been.
                Ok(shm::DocBytes::NotStored) => Ok(ShmDoc::Unavailable),
                Ok(shm::DocBytes::Stored(bytes)) => {
                    metrics::index_serve();
                    f(bytes, rec.generation as i64).map(ShmDoc::Served)
                }
            }
        }
        // A miss or a tombstone is a definitive absence while the daemon is
        // coherent — no filesystem walk, no file read.
        Lookup::Found(_) | Lookup::Absent => {
            metrics::authoritative_miss();
            Ok(ShmDoc::Absent)
        }
        Lookup::Invalid => {
            metrics::shm_invalid(); // never trust a failed validation
            Ok(ShmDoc::Unavailable)
        }
    }
}

/// As [`with_shm_doc`], but does not raise on a document flagged corrupt —
/// `getRaw()` reports stored bytes rather than decoding, so a broken document
/// is data, not an error.
///
/// NOTE: the daemon does not keep the bytes of a corrupt document
/// (`model::load_docs` stores an empty `raw` for it), so in SHM mode this
/// yields an empty string for one; only fallback mode reads the real bytes off
/// disk. Preserved as-is — callers use `getRaw()` for valid documents, and
/// changing it would mean storing known-bad payloads in shared memory.
fn with_shm_doc_raw<T>(
    cfg: &Config,
    name: &str,
    lang: &str,
    f: impl FnOnce(&[u8]) -> T,
) -> Result<ShmDoc<T>, DbError> {
    let Some(seg) = seg_current(cfg) else {
        return Ok(ShmDoc::Unavailable);
    };
    match seg.lookup(name) {
        Lookup::Found(rec) if !rec.is_tombstone() => {
            metrics::shm_hit();
            match rec.langs().into_iter().find(|l| l.lang == lang) {
                Some(l) if l.flags & shm::LANG_HAS_RAW != 0 => {
                    metrics::index_serve();
                    Ok(ShmDoc::Served(f(l.doc)))
                }
                // Present, but its bytes are not in the segment. getRaw()'s
                // whole contract is byte fidelity, and re-serializing from the
                // image would not deliver it: escaping normalizes (PHP's `\/`
                // and `\uXXXX` vs serde's neither), whitespace and indentation
                // are gone, and float formatting changes. Its callers do
                // textual substitution on the result (hili.doctor.hook.inc
                // str_replace's the raw string and writes it back), so a
                // normalized re-serialization would rewrite the escaping of
                // every document it touches. It reads the file instead — this
                // is integrity/doctor/migration code, not the render path, and
                // it can afford an open+read.
                Some(_) => Ok(ShmDoc::Unavailable),
                None => Ok(ShmDoc::Absent),
            }
        }
        Lookup::Found(_) | Lookup::Absent => {
            metrics::authoritative_miss();
            Ok(ShmDoc::Absent)
        }
        Lookup::Invalid => {
            metrics::shm_invalid();
            Ok(ShmDoc::Unavailable)
        }
    }
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
                let generation = rec.generation as i64;
                match rec.doc(lang) {
                    Err(()) => {
                        metrics::corrupt_json();
                        return Err(DbError::CorruptJson(format!(
                            "invalid JSON in {}/{}",
                            row.path,
                            store::doc_file(lang)
                        )));
                    }
                    Ok(shm::DocBytes::Absent) => return Ok(None),
                    Ok(shm::DocBytes::Stored(bytes)) => {
                        metrics::index_serve();
                        return parse_cached(&row.name, lang, bytes, generation).map(Some);
                    }
                    // The document exists but the segment holds only its image.
                    // Walk the image into a `Value` rather than parsing bytes
                    // that are no longer there. Same generation-keyed cache the
                    // parse used, so a second consumer in one request pays
                    // nothing either way — and no pin is taken, because this
                    // builds owned data and never points PHP at the mapping.
                    Ok(shm::DocBytes::NotStored) => {
                        if let Some(v) = cache::get(&row.name, lang, generation) {
                            return Ok(Some(v));
                        }
                        if let Ok(Some(img_bytes)) = rec.image(lang) {
                            if let Some((img, _)) = open_image(cfg, &seg, img_bytes, false) {
                                if let Some(v) = img.to_value(img.root, 0) {
                                    metrics::index_serve();
                                    let rc = Rc::new(v);
                                    cache::put(&row.name, lang, generation, rc.clone());
                                    return Ok(Some(rc));
                                }
                                metrics::img_invalid();
                            }
                        }
                        // No usable image either (over `image_max_doc`, images
                        // off, damaged). The file is the only source left.
                    }
                }
            }
        }
        // Absent/tombstoned in SHM while the row resolved a moment ago: a
        // racing delete. Fall through to the file — source of truth.
    }
    load_doc_file(cfg, row, lang)
}

/// Read + decode a document straight from the file. Deliberately UNCACHED:
/// mtime+size cannot validate same-second writes (contract scenario 5), and the
/// files are the source of truth.
fn load_doc_file(cfg: &Config, row: &NodeRow, lang: &str) -> Result<Option<Rc<Value>>, DbError> {
    let _ = cfg;
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

/// One document, decoded, resolved by NAME in a single hash probe.
///
/// The SHM path never builds the node's absolute path — that string is only
/// needed to open a file, which only the fallback branch does. Reserving it for
/// that branch is why this exists alongside [`load_doc`], which callers holding
/// a [`NodeRow`] already (find, update) keep using.
fn load_doc_by_name(cfg: &Config, name: &str, lang: &str) -> Result<Option<Rc<Value>>, DbError> {
    let started = Instant::now();
    let r = (|| {
        ensure_valid_name(name)?;
        match with_shm_doc(cfg, name, lang, |bytes, generation| {
            parse_cached(name, lang, bytes, generation)
        })? {
            ShmDoc::Served(doc) => Ok(Some(doc)),
            ShmDoc::Absent => Ok(None),
            ShmDoc::Unavailable => match resolve_node(cfg, name)? {
                Some(row) => load_doc_file(cfg, &row, lang),
                None => Ok(None),
            },
        }
    })();
    metrics::record_read(started.elapsed());
    r
}

// ---------------------------------------------------------------------------
// Composed load (contract §3 `load`)
// ---------------------------------------------------------------------------

/// Everything `QuantaDb::load()` was asked for, normalized once so the probe
/// below re-reads no policy.
struct LoadOpts {
    /// Languages to try, in order; `""` is the neutral document.
    langs: Vec<String>,
    /// The directory the caller believes this node lives in, in the EXTENSION's
    /// root terms (the PHP wrapper translates a docroot spelling first).
    at: Option<PathBuf>,
    /// `getObject()` shape (JSON objects become stdClass, all the way down) as
    /// against `get()` shape (associative arrays).
    objects: bool,
    /// Whether to report the node's absolute path. Deliberately tied to `at`
    /// being absent: passing `at` IS the statement "I already know where this
    /// node is", and building the path anyway is precisely the wasted `String`
    /// this method exists to remove.
    want_path: bool,
}

/// A document `load()` managed to produce.
struct LoadHit {
    json: Zval,
    /// The language the document actually came from; `""` = neutral.
    lang: String,
    generation: i64,
    /// Only ever `Some` when the caller did not pass `at`.
    path: Option<String>,
}

/// Outcome of `load()`'s single segment probe.
enum LoadOutcome {
    Hit(LoadHit),
    /// The segment is authoritative and the answer is "nothing": no such node,
    /// not the node at `at`, or no document in any language the caller allowed.
    Absent,
    /// No usable segment (fallback mode, or a record that failed validation) —
    /// the caller must resolve the node and read the file.
    Unavailable,
}

fn parse_load_opts(opts: Option<&ZendHashTable>) -> Result<LoadOpts, DbError> {
    let r = Reader::new(opts, &["lang", "fallback", "at", "as"], "opts")?;
    let lang = normalize_lang(r.str_opt("lang")?)?;
    // Default TRUE: Quanta reads the language file and then the neutral one, in
    // that order, on every single node load — so the default is the policy the
    // caller this replaces already had.
    let fallback = r.raw("fallback").map_or(true, |z| z.bool().unwrap_or(true));
    let objects = match r.str_opt("as")?.as_deref() {
        None | Some("object") => true,
        Some("array") => false,
        Some(other) => return Err(DbError::BadArgs(format!("invalid opts 'as' value '{other}'"))),
    };
    let mut langs = Vec::with_capacity(2);
    langs.push(lang.clone());
    // Nothing to fall back TO when the request was already neutral.
    if fallback && !lang.is_empty() {
        langs.push(String::new());
    }
    let at = r.str_opt("at")?.map(PathBuf::from);
    let want_path = at.is_none();
    Ok(LoadOpts {
        langs,
        at,
        objects,
        want_path,
    })
}

/// True when `dir` and `at` name the same directory.
///
/// Cheap compare first: `Path` equality is component-wise, so it already
/// absorbs a trailing slash or a `.` segment without touching the filesystem.
/// `canonicalize` is the tie-breaker for what it cannot see — two spellings of
/// one directory through a `sites/<alias>` symlink — and is the same
/// `realpath()` pair `Node::loadJSON` ran in PHP, now reached only when the
/// two spellings genuinely differ.
fn same_dir(dir: &Path, at: &Path) -> bool {
    if dir == at {
        return true;
    }
    match (fs::canonicalize(dir), fs::canonicalize(at)) {
        (Ok(a), Ok(b)) => a == b,
        _ => false,
    }
}

/// [`same_dir`] against a record's own directory, without building it.
///
/// `at` almost always came out of this very index
/// (`Environment::nodePath()` -> `QuantaDb::path()`), so stripping the root off
/// it and comparing the remainder with `rel_path()` settles the question with no
/// allocation at all. That is the point of the whole exercise: the absolute path
/// `String` `resolve_node()` builds for every caller existed only to be compared
/// once, in PHP, and dropped.
fn rec_is_at(cfg: &Config, rel_path: &str, at: &Path) -> bool {
    if at
        .strip_prefix(&cfg.root)
        .is_ok_and(|rest| rest == Path::new(rel_path))
    {
        return true;
    }
    same_dir(&cfg.root.join(rel_path), at)
}

/// Materialize one language's document from its image, or `Ok(None)` when there
/// is no usable image for it (images off, oversize, damaged, or a `getObject`
/// root shape the image does not serve directly).
///
/// Takes the record the caller already holds, so it costs no probe of its own —
/// which is the entire reason `load()` does not route through
/// [`with_shm_image`].
fn image_zval(
    cfg: &Config,
    seg: &Rc<shm::SegmentReader>,
    rec: &shm::RecordView<'_>,
    lang: &str,
    objects: bool,
) -> Result<Option<Zval>, DbError> {
    if !image_enabled(cfg) {
        return Ok(None);
    }
    let img_bytes = match rec.image(lang) {
        // Unreachable as things stand: the caller picked this language through
        // `doc()`, which already raised CORRUPT_JSON for a corrupt one. Kept
        // explicit so a future caller cannot serve a corrupt document as merely
        // "no image".
        Err(()) => return Ok(None),
        Ok(None) => {
            metrics::img_absent();
            return Ok(None);
        }
        Ok(Some(b)) => b,
    };
    let Some((img, base)) = open_image(cfg, seg, img_bytes, true) else {
        return Ok(None);
    };
    // The image path only serves object roots directly; PHP's `(object)` cast
    // rules for array/scalar roots stay in one place (json_root_to_object_zval).
    if objects && !img.root_is_map() {
        return Ok(None);
    }
    metrics::index_serve();
    metrics::img_serve();
    materialize(&img, base, img.root, 0, objects).map(Some)
}

/// Existence, path identity, language selection and materialisation, all out of
/// ONE `seg.lookup()`.
///
/// The three calls this replaces (`path()`, `getObject($lang)`,
/// `getObject(null)`) each interrogated the same record. The view returned by a
/// single probe already carries the node's directory, its language list, its
/// documents, its images and its generation, so asking for them one at a time
/// was two extra hash probes and one absolute-path `String` spent re-answering
/// what the first probe had in hand.
fn shm_load(cfg: &Config, name: &str, o: &LoadOpts) -> Result<LoadOutcome, DbError> {
    let Some(seg) = seg_current(cfg) else {
        return Ok(LoadOutcome::Unavailable);
    };
    let rec = match seg.lookup(name) {
        Lookup::Found(rec) if !rec.is_tombstone() => rec,
        // A miss or a tombstone is a definitive absence while the daemon is
        // coherent — no filesystem walk, no file read.
        Lookup::Found(_) | Lookup::Absent => {
            metrics::authoritative_miss();
            return Ok(LoadOutcome::Absent);
        }
        Lookup::Invalid => {
            metrics::shm_invalid(); // never trust a failed validation
            return Ok(LoadOutcome::Unavailable);
        }
    };
    metrics::shm_hit();
    // "That name resolves somewhere else" is an absence for THIS caller, never
    // a licence to hand back the other node's document. Containers built from an
    // explicit path (NodeFactory::loadFromRealPath) are why the check exists.
    if let Some(at) = &o.at {
        if !rec_is_at(cfg, rec.rel_path(), at) {
            return Ok(LoadOutcome::Absent);
        }
    }
    // Language choice is made on DOCUMENT presence, not image presence: a
    // document that exists but could not be imaged (oversize, images off) must
    // still beat the neutral fallback, or `load()` would quietly answer in a
    // different language than `getObject()` does.
    // `None` bytes = the document exists but the segment keeps only its image,
    // which is the normal case; it still wins the language choice, and only the
    // no-image fallback below has to care.
    let mut chosen: Option<(&str, Option<&[u8]>)> = None;
    for lang in &o.langs {
        match rec.doc(lang) {
            Err(()) => {
                metrics::corrupt_json();
                return Err(DbError::CorruptJson(format!(
                    "invalid JSON in {}/{}",
                    rec.rel_path(),
                    store::doc_file(lang)
                )));
            }
            Ok(shm::DocBytes::Absent) => continue,
            Ok(shm::DocBytes::NotStored) => {
                chosen = Some((lang.as_str(), None));
                break;
            }
            Ok(shm::DocBytes::Stored(bytes)) => {
                chosen = Some((lang.as_str(), Some(bytes)));
                break;
            }
        }
    }
    let Some((lang, bytes)) = chosen else {
        return Ok(LoadOutcome::Absent);
    };
    let generation = rec.generation as i64;
    let path = o
        .want_path
        .then(|| cfg.root.join(rec.rel_path()).to_string_lossy().to_string());
    let json = match image_zval(cfg, &seg, &rec, lang, o.objects)? {
        Some(z) => z,
        None => match bytes {
            // Same fallback `getObject()` takes, minus its second probe: the
            // bytes are already here, so only the parse cache is consulted.
            Some(bytes) => {
                metrics::index_serve();
                let doc = parse_cached(name, lang, bytes, generation)?;
                if o.objects {
                    json_root_to_object_zval(&doc)?
                } else {
                    json_to_zval(&doc)?
                }
            }
            // No image AND no stored bytes: nothing in the segment can answer.
            // Hand it to `load_impl`'s resolve + file read rather than
            // duplicating that path here.
            None => return Ok(LoadOutcome::Unavailable),
        },
    };
    Ok(LoadOutcome::Hit(LoadHit {
        json,
        lang: lang.to_string(),
        generation,
        path,
    }))
}

/// `load()` end to end: the single-probe segment path, then — only when there is
/// no usable segment — the same resolve + uncached file read `getObject()` falls
/// back to.
fn load_impl(cfg: &Config, name: &str, o: &LoadOpts) -> Result<Option<LoadHit>, DbError> {
    ensure_valid_name(name)?;
    // Time the segment path like every other read.
    //
    // Not decoration: `load()` took over the hot read from `getObject()`, and
    // `getObject()`'s image branch never called this — so once Node::loadJSON
    // switched, `reads` / `read_ns` / `avg_read_ms` went to ZERO on a pod
    // serving 23,000 documents a render, and the READS section of `qdbstat`
    // (the operator's only latency view) went blind. A read that is not counted
    // is a read nobody can find when it gets slow.
    let started = Instant::now();
    let outcome = shm_load(cfg, name, o);
    metrics::record_read(started.elapsed());
    match outcome? {
        LoadOutcome::Hit(hit) => return Ok(Some(hit)),
        LoadOutcome::Absent => return Ok(None),
        LoadOutcome::Unavailable => {}
    }
    // Fallback mode only, so this branch may cost what a file read costs — and
    // is the one place the node's absolute path is genuinely needed, to open
    // the file with.
    let started = Instant::now();
    let r = (|| {
        let Some(row) = resolve_node(cfg, name)? else {
            return Ok(None);
        };
        if let Some(at) = &o.at {
            if !same_dir(Path::new(&row.path), at) {
                return Ok(None);
            }
        }
        for lang in &o.langs {
            let Some(doc) = load_doc_file(cfg, &row, lang)? else {
                continue;
            };
            let json = if o.objects {
                json_root_to_object_zval(&doc)?
            } else {
                json_to_zval(&doc)?
            };
            return Ok(Some(LoadHit {
                json,
                lang: lang.clone(),
                generation: row.generation,
                path: o.want_path.then(|| row.path.clone()),
            }));
        }
        Ok(None)
    })();
    metrics::record_read(started.elapsed());
    r
}

fn load_hit_zval(hit: LoadHit) -> Result<Zval, DbError> {
    let mut ht = ZendHashTable::with_capacity(4);
    let ins = |ht: &mut ZendHashTable, k: &str, v: Zval| -> Result<(), DbError> {
        ht.insert(ArrayKey::Str(k), v)
            .map_err(|e| DbError::Io(format!("array conversion: {e}")))
    };
    ins(&mut ht, "json", hit.json)?;
    let mut z = Zval::new();
    z.set_string(&hit.lang, false)
        .map_err(|e| DbError::Io(e.to_string()))?;
    ins(&mut ht, "lang", z)?;
    let mut z = Zval::new();
    z.set_long(hit.generation);
    ins(&mut ht, "generation", z)?;
    // Present only when the caller did not pass 'at' — see LoadOpts::want_path.
    if let Some(p) = &hit.path {
        let mut z = Zval::new();
        z.set_string(p, false)
            .map_err(|e| DbError::Io(e.to_string()))?;
        ins(&mut ht, "path", z)?;
    }
    let mut out = Zval::new();
    out.set_hashtable(ht);
    Ok(out)
}

/// The raw JSON bytes of one document, resolved by NAME in a single hash probe.
/// SHM mode serves the exact stored bytes with one copy into a `zend_string`
/// and no UTF-8 revalidation — the daemon already read the file as UTF-8, and
/// re-scanning a large `body` here was pure overhead.
fn load_raw_by_name(cfg: &Config, name: &str, lang: &str) -> Result<Option<Zval>, DbError> {
    let started = Instant::now();
    let r = (|| {
        ensure_valid_name(name)?;
        // getRaw() serves the stored bytes verbatim, INCLUDING a corrupt
        // document — it is the escape hatch for inspecting one. So the corrupt
        // flag must not turn into an exception here the way it does for get().
        let served = with_shm_doc_raw(cfg, name, lang, |bytes| {
            let mut z = Zval::new();
            z.set_zend_string(ZendStr::new(bytes, false));
            z
        })?;
        match served {
            ShmDoc::Served(z) => Ok(Some(z)),
            ShmDoc::Absent => Ok(None),
            ShmDoc::Unavailable => match resolve_node(cfg, name)? {
                Some(row) => match store::read_doc(Path::new(&row.path), lang)
                    .inspect_err(|_| metrics::io_error())?
                {
                    Some((raw, _)) => {
                        metrics::file_read(raw.len() as u64);
                        metrics::fallback_read();
                        let mut z = Zval::new();
                        z.set_zend_string(ZendStr::new(raw.as_bytes(), false));
                        Ok(Some(z))
                    }
                    None => Ok(None),
                },
                None => Ok(None),
            },
        }
    })();
    metrics::record_read(started.elapsed());
    r
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

/// Write path shared by put/putRaw/update. Caller must hold the node lock.
///
/// `raw` is what lands on disk, byte for byte — `putRaw` exists precisely so a
/// caller can control those bytes, so nothing here may re-serialize them.
/// `parsed` is the same document already decoded; it only seeds the parse cache,
/// and every caller has it already (either it built `raw` from it, or it had to
/// parse `raw` to validate it).
fn put_inner(
    cfg: &Config,
    name: &str,
    raw: &str,
    parsed: Value,
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
    store::write_doc(&path, lang, raw)?;
    let father_db = store::father_of(cfg, &path);

    // Push to the daemon (single SHM writer); its ack means "published".
    let ack = notify_daemon(cfg, ipc::msg_upsert(name, &rel_of(cfg, &path)));

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
        cache::put(name, lang, generation, Rc::new(parsed));
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

/// [`json_value_at`] evaluated against the pre-decoded image instead of a
/// `serde_json::Value`, returning the value node the dotted path lands on.
///
/// Same navigation rules, deliberately: object keys by name, array elements by
/// decimal index, and anything else (a scalar with path left to walk, a missing
/// key, an out-of-range or non-numeric index) is a miss. It descends the map
/// pairs comparing the key's bytes, which is why it needs no `Value` at all —
/// where the parse path had to materialize the WHOLE document, and cache an
/// `Rc<Value>` of it per node, before it could look at one field.
///
/// `Err(())` is a DAMAGED image (an interior offset that does not bounds-check),
/// kept distinct from `Ok(None)` so the caller decodes the document instead of
/// reading damage as "this candidate does not match". A bad image may cost time;
/// it must never silently change a result set.
///
/// Lives here rather than in `image.rs` because these are `find`'s semantics,
/// not the image format's.
fn image_value_at<'a>(
    img: &image::Image<'a>,
    path: &str,
) -> Result<Option<image::Node<'a>>, ()> {
    let mut off = img.root;
    for seg in path.split('.') {
        off = match img.node(off).ok_or(())? {
            image::Node::Map { count, table } => {
                // Keys are unique — the daemon images an already-parsed
                // document, and JSON object keys collapse last-wins on parse —
                // so the first hit is the only hit.
                let mut hit = None;
                for i in 0..count {
                    let (ko, vo) = img.map_pair(table, i).ok_or(())?;
                    if img.string_bytes(ko).ok_or(())? == seg.as_bytes() {
                        hit = Some(vo);
                        break;
                    }
                }
                match hit {
                    Some(vo) => vo,
                    None => return Ok(None),
                }
            }
            image::Node::List { count, table } => {
                let Ok(i) = seg.parse::<u32>() else {
                    return Ok(None);
                };
                if i >= count {
                    return Ok(None);
                }
                img.list_elem(table, i).ok_or(())?
            }
            _ => return Ok(None),
        };
    }
    img.node(off).ok_or(()).map(Some)
}

/// [`json_eq`] with the left-hand side still in the image.
///
/// `expected` is always a scalar — `parse_criteria` rejects anything else — so
/// the cases here are exactly the ones `json_eq` can answer true for, and every
/// other pairing falls to its `a == b` arm, which is false across variants.
/// Numbers compare as `f64` on both sides, as `json_eq` does, so an integer
/// predicate still matches a float document value and vice versa.
fn image_node_eq(node: &image::Node<'_>, expected: &Value) -> bool {
    match (node, expected) {
        (image::Node::Null, Value::Null) => true,
        (image::Node::Bool(b), Value::Bool(e)) => b == e,
        (image::Node::Long(i), Value::Number(_)) => expected.as_f64() == Some(*i as f64),
        (image::Node::Double(d), Value::Number(_)) => expected.as_f64() == Some(*d),
        (image::Node::Str { bytes, .. }, Value::String(s)) => *bytes == s.as_bytes(),
        _ => false,
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

/// One `find` candidate, resolved at most once.
///
/// `find_impl` used to call `resolve_node()` in each of its three passes —
/// `where` filtering, non-name ordering, result shaping — so a
/// `find(where:, order_by: 'json:…', return: 'data')` over N candidates spent 3N
/// hash probes and 3N absolute-path `String`s answering the same question three
/// times. Resolution now happens on first demand and is remembered, and a pass
/// that needs no path at all (a `where` answered from the image, `return:
/// 'names'`) still pays nothing.
struct Candidate {
    name: String,
    row: Option<NodeRow>,
    /// `row == None` is itself an answer ("this name no longer resolves"), so
    /// "not asked yet" needs a flag of its own rather than being inferred.
    resolved: bool,
}

impl Candidate {
    fn new(name: String) -> Self {
        Self {
            name,
            row: None,
            resolved: false,
        }
    }

    fn row(&mut self, cfg: &Config) -> Result<Option<&NodeRow>, DbError> {
        if !self.resolved {
            self.row = resolve_node(cfg, &self.name)?;
            self.resolved = true;
        }
        Ok(self.row.as_ref())
    }
}

/// Every `where` predicate against one image, or `None` when the image could not
/// answer (see [`image_value_at`]). Stops at the first failing predicate, so a
/// query over a wide candidate set touches one field of each document rather
/// than all of them.
fn image_where_all(img: &image::Image<'_>, preds: &[(String, Value)]) -> Option<bool> {
    for (path, expected) in preds {
        match image_value_at(img, path) {
            // A missing field never matches — `json_value_at(..).map(..) ==
            // Some(true)` is the rule this mirrors.
            Ok(None) => return Some(false),
            Ok(Some(node)) if !image_node_eq(&node, expected) => return Some(false),
            Ok(Some(_)) => {}
            Err(()) => return None,
        }
    }
    Some(true)
}

/// Does this candidate satisfy every `where` predicate?
///
/// Fast path: walk the document image — no parse, no allocation, no parse-cache
/// entry, and a stop at the first failing predicate, where the parse path has to
/// materialize the entire document (and hold an `Rc<Value>` of it per node)
/// before it can test one field. That matters most exactly where `find` is
/// weakest: `limit` cannot be applied before the filter, so a `where` over
/// thousands of candidates used to decode thousands of documents.
///
/// A candidate with no usable image — images off, ABI mismatch, oversize
/// document, damaged image, fallback mode — falls back to the previous path
/// unchanged, so results never move.
fn where_matches(
    cfg: &Config,
    c: &mut Candidate,
    preds: &[(String, Value)],
    lang: &str,
) -> Result<bool, DbError> {
    // `resolve_node()` raised this for a name the store handed back but the API
    // will not address; the image path skips resolution, so raise it here or the
    // error would disappear on exactly the documents this shortcut serves.
    ensure_valid_name(&c.name)?;
    if let Some(ShmDoc::Served(Some(matched))) =
        with_shm_image(cfg, &c.name, lang, |img, _base| Ok(image_where_all(img, preds)))?
    {
        return Ok(matched);
    }
    let Some(row) = c.row(cfg)? else {
        return Ok(false);
    };
    let Some(doc) = load_doc(cfg, row, lang)? else {
        return Ok(false);
    };
    Ok(preds.iter().all(|(path, expected)| {
        json_value_at(&doc, path).map(|v| json_eq(v, expected)) == Some(true)
    }))
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

    let mut cands: Vec<Candidate> = find_candidates(cfg, &crit)?
        .into_iter()
        .map(Candidate::new)
        .collect();

    // Filter by 'where' (missing document never matches).
    if !crit.where_.is_empty() {
        let mut kept = Vec::with_capacity(cands.len());
        for mut c in cands {
            if where_matches(cfg, &mut c, &crit.where_, &lang)? {
                kept.push(c);
            }
        }
        cands = kept;
    }

    // Ordering. Ties break on name in every mode, so the result is total.
    if order_by == "name" {
        cands.sort_by(|a, b| a.name.cmp(&b.name));
    } else if order_by == "mtime" {
        let mut keyed: Vec<(i64, Candidate)> = Vec::with_capacity(cands.len());
        for mut c in cands {
            let m = c.row(cfg)?.map(|r| node_mtime(cfg, r)).unwrap_or(0);
            keyed.push((m, c));
        }
        keyed.sort_by(|a, b| a.0.cmp(&b.0).then_with(|| a.1.name.cmp(&b.1.name)));
        cands = keyed.into_iter().map(|(_, c)| c).collect();
    } else if let Some(jpath) = order_by.strip_prefix("json:") {
        let mut keyed: Vec<(Option<Value>, Candidate)> = Vec::with_capacity(cands.len());
        for mut c in cands {
            let val = match c.row(cfg)? {
                Some(row) => {
                    load_doc(cfg, row, &lang)?.and_then(|d| json_value_at(&d, jpath).cloned())
                }
                None => None,
            };
            keyed.push((val, c));
        }
        keyed.sort_by(|a, b| cmp_json(a.0.as_ref(), b.0.as_ref()).then_with(|| a.1.name.cmp(&b.1.name)));
        cands = keyed.into_iter().map(|(_, c)| c).collect();
    } else {
        return Err(DbError::BadArgs(format!("invalid order_by '{order_by}'")));
    }
    if order == "desc" {
        cands.reverse();
    }

    // Slice.
    let cands: Vec<Candidate> = cands
        .into_iter()
        .skip(offset)
        .take(limit.map(|l| l.max(0) as usize).unwrap_or(usize::MAX))
        .collect();

    // Shape the return value.
    match ret_mode.as_str() {
        "names" => {
            let names: Vec<String> = cands.into_iter().map(|c| c.name).collect();
            strings_to_zval(&names)
        }
        "data" => {
            let mut ht = ZendHashTable::new();
            for mut c in cands {
                let v = match c.row(cfg)? {
                    Some(row) => match load_doc(cfg, row, &lang)? {
                        Some(doc) => json_to_zval(&doc)?,
                        None => null_zval(),
                    },
                    None => null_zval(),
                };
                ht.insert(ArrayKey::from(c.name), v)
                    .map_err(|e| DbError::Io(format!("array conversion: {e}")))?;
            }
            let mut z = Zval::new();
            z.set_hashtable(ht);
            Ok(z)
        }
        _ => {
            let mut ht = ZendHashTable::new();
            for mut c in cands {
                let v = match c.row(cfg)? {
                    Some(row) => meta_zval(cfg, row)?,
                    None => null_zval(),
                };
                ht.insert(ArrayKey::from(c.name), v)
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
/// `p` with a `from` prefix rewritten to `to`; identity when `p` is elsewhere.
/// Equal paths strip to an empty remainder, so `to.join("")` yields `to`.
fn relocate(p: &Path, from: &Path, to: &Path) -> PathBuf {
    match p.strip_prefix(from) {
        Ok(rest) => to.join(rest),
        Err(_) => p.to_path_buf(),
    }
}

/// Re-point one container's symlink at a node that has moved. Writes a
/// temporary link and renames it over the target, so a concurrent reader of the
/// container sees either the old link or the new one — never a missing member.
///
/// Best effort by design: a failure here leaves a dangling link that `reindex()`
/// repairs, which is strictly better than unwinding a rename that already
/// succeeded.
fn repoint_link(container_dir: &Path, old_name: &str, new_name: &str, target: &Path) {
    let old_link = container_dir.join(old_name);
    let is_link = fs::symlink_metadata(&old_link)
        .map(|m| m.file_type().is_symlink())
        .unwrap_or(false);
    if !is_link {
        return; // a real directory of that name is not ours to touch
    }
    let tmp = container_dir.join(format!(".{new_name}.tmp.{}", std::process::id()));
    let _ = fs::remove_file(&tmp);
    if std::os::unix::fs::symlink(target, &tmp).is_err() {
        return;
    }
    let new_link = container_dir.join(new_name);
    if fs::rename(&tmp, &new_link).is_err() {
        let _ = fs::remove_file(&tmp);
        return;
    }
    if new_link != old_link {
        let _ = fs::remove_file(&old_link);
    }
}

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
    ensure_valid_name(&name)?;
    if let Some(ShmDoc::Served(z)) = with_shm_image(cfg, &name, &lang, |img, base| {
        materialize(img, base, img.root, 0, false)
    })? {
        return Ok(z);
    }
    match load_doc_by_name(cfg, &name, &lang)? {
        Some(doc) => Ok(json_to_zval(&doc)?),
        None => Ok(null_zval()),
    }
}

/// The node's data document as a `stdClass`, or null — the exact value
/// `(object) json_decode($raw)` produces, nested shapes included, with no file
/// read and no JSON parse (the decoded document is cached per process and
/// validated by the node's generation).
///
/// Unlike every other reader on this class, the returned value is a freshly
/// allocated, unshared, fully MUTABLE object: two calls return two independent
/// objects. Quanta's `$node->json` is written to, appended to and unset all
/// over the codebase, so this is the deliberate exception to the contract's
/// "arrays at the boundary, treat results as immutable" rule (§1.2).
pub fn get_object(name: String, lang: Option<String>) -> PhpResult<Zval> {
    let cfg = config()?;
    let lang = normalize_lang(lang)?;
    ensure_valid_name(&name)?;
    // The image path only serves object roots directly; PHP's `(object)` cast
    // rules for array/scalar roots stay in one place (json_root_to_object_zval).
    if let Some(ShmDoc::Served(z)) = with_shm_image(cfg, &name, &lang, |img, base| {
        if img.root_is_map() {
            materialize(img, base, img.root, 0, true).map(Some)
        } else {
            Ok(None)
        }
    })? {
        if let Some(z) = z {
            return Ok(z);
        }
    }
    match load_doc_by_name(cfg, &name, &lang)? {
        Some(doc) => Ok(json_root_to_object_zval(&doc)?),
        None => Ok(null_zval()),
    }
}

/// One node's document with the caller's language policy and path check applied
/// INSIDE the single probe that already holds the record — the composed read
/// behind `Node::loadJSON()`.
///
/// Explicitly NOT a primitive: `get`/`getObject`/`path` are unchanged and stay
/// the vocabulary for everything else. This exists because the hot path needs
/// all three answers at once, and asking for them separately cost three probes
/// plus an absolute-path `String` that was built in Rust, compared once in PHP
/// and dropped.
///
/// `$opts`:
///   - `lang`     — the language to try first; null / `''` = the neutral document
///   - `fallback` — default true: retry the neutral document when `lang` has none
///   - `at`       — the directory the caller believes this node lives in, in the
///                  EXTENSION's own root terms (`quanta_db.root`), not a site
///                  docroot. Given, the identity check happens against the
///                  record and no absolute path is built
///   - `as`       — `'object'` (default; `getObject()` shape all the way down,
///                  which is what Quanta reads) or `'array'` (`get()` shape)
///
/// Returns null for "no such node", "it is not the node at `at`" and "no
/// document in any language tried" alike: to the caller all three mean the same
/// thing, which is that it must decide for itself what an absent document means.
/// A corrupt document still raises CORRUPT_JSON exactly as `getObject()` does —
/// that is an error, not an absence.
///
/// `path` is in the result ONLY when `at` was NOT supplied. Passing `at` is the
/// caller saying it already knows where the node is, so building the path would
/// be the very allocation this method removes; omitting `at` is the caller
/// asking for it.
pub fn load(name: String, opts: Option<&ZendHashTable>) -> PhpResult<Zval> {
    let cfg = config()?;
    let o = parse_load_opts(opts)?;
    match load_impl(cfg, &name, &o)? {
        Some(hit) => Ok(load_hit_zval(hit)?),
        None => Ok(null_zval()),
    }
}

/// The raw JSON document as a string (SHM bytes verbatim), or null. Lets hot
/// call sites pair a zero-syscall read with PHP's native json_decode instead
/// of paying the Rust-side array construction. Contract 1.1 addition.
pub fn get_raw(name: String, lang: Option<String>) -> PhpResult<Zval> {
    let cfg = config()?;
    let lang = normalize_lang(lang)?;
    match load_raw_by_name(cfg, &name, &lang)? {
        Some(z) => Ok(z),
        None => Ok(null_zval()),
    }
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
    let raw = serde_json::to_string(&value).map_err(|e| DbError::Io(e.to_string()))?;
    let _lk = lock::acquire(cfg, &name)?;
    put_inner(cfg, &name, &raw, value, &lang, father.as_deref())?;
    Ok(true)
}

/// `put` for callers that already hold the serialized document. The bytes are
/// stored verbatim, which is the point: PHP's `json_encode` escapes `/` and
/// non-ASCII (`http:\/\/a`, `città`) while `serde_json` does neither, so
/// round-tripping a document through `put` rewrites it. `putRaw` lets a caller
/// keep byte stability across writers.
pub fn put_raw(name: String, json: String, opts: Option<&ZendHashTable>) -> PhpResult<bool> {
    let cfg = config()?;
    ensure_valid_name(&name)?;
    let r = Reader::new(opts, &["lang", "father"], "opts")?;
    let lang = normalize_lang(r.str_opt("lang")?)?;
    let father = r.str_opt("father")?;
    // Validate before writing, never after: the daemon latches an unparsable
    // document as LANG_CORRUPT and every later read of the node then throws.
    // Bad input is BAD_ARGS — CORRUPT_JSON means the *stored* file is bad.
    let parsed: Value = serde_json::from_str(&json)
        .map_err(|e| DbError::BadArgs(format!("putRaw payload is not valid JSON: {e}")))?;
    let _lk = lock::acquire(cfg, &name)?;
    put_inner(cfg, &name, &json, parsed, &lang, father.as_deref())?;
    metrics::record_raw_write();
    Ok(true)
}

/// Remove one language's document from a node that itself stays. A node with no
/// documents at all is a legal state (`model::load_docs` simply finds none), so
/// this notifies with an *upsert*: the daemon re-reads the directory and the
/// language list corrects itself.
pub fn delete_doc(name: String, lang: Option<String>) -> PhpResult<bool> {
    let cfg = config()?;
    ensure_valid_name(&name)?;
    let lang = normalize_lang(lang)?;
    let _lk = lock::acquire(cfg, &name)?;
    let Some(row) = resolve_node(cfg, &name)? else {
        return Ok(false);
    };
    let path = PathBuf::from(&row.path);
    if !store::remove_doc(&path, &lang)? {
        return Ok(false);
    }
    let rel = rel_of(cfg, &path);
    notify_daemon(cfg, ipc::msg_upsert(&name, &rel));
    cache::invalidate(&name);
    metrics::record_doc_delete();
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
    let raw = serde_json::to_string(&value).map_err(|e| DbError::Io(e.to_string()))?;
    put_inner(cfg, &name, &raw, value, &lang, None)?;
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

/// Relocate a node: a new father, a new name, or both. The directory is
/// renamed, so descendants travel with it, and every inbound symlink is
/// re-pointed — links are stored as absolute paths (see `link`), so without
/// that step every container membership of the node would dangle.
///
/// Not atomic end to end. The rename is atomic and each link re-point is
/// atomic, but a crash between them leaves dangling links that `reindex()`
/// repairs. Contract §3 states this limit rather than implying more.
#[php(name = "move")]
pub fn move_node(
    name: String,
    new_father: Option<String>,
    opts: Option<&ZendHashTable>,
) -> PhpResult<bool> {
    let cfg = config()?;
    ensure_valid_name(&name)?;
    let r = Reader::new(opts, &["name", "if_exists"], "opts")?;
    let new_name = match r.str_opt("name")? {
        Some(n) => {
            ensure_valid_name(&n)?;
            n
        }
        None => name.clone(),
    };
    let replace = match r.str_opt("if_exists")?.as_deref() {
        None | Some("error") => false,
        Some("replace") => true,
        Some(other) => {
            return Err(DbError::BadArgs(format!("invalid if_exists value '{other}'")).into())
        }
    };

    let _lk = lock::acquire(cfg, &name)?;
    let Some(row) = resolve_node(cfg, &name)? else {
        return Ok(false);
    };
    let old_path = PathBuf::from(&row.path);

    // Destination directory: the named father, or the node's current parent
    // when only the name is changing.
    let father_dir = match &new_father {
        Some(f) => {
            ensure_valid_name(f)?;
            let f_row = resolve_node(cfg, f)?
                .ok_or_else(|| DbError::BadArgs(format!("father node '{f}' not found")))?;
            PathBuf::from(f_row.path)
        }
        None => old_path
            .parent()
            .ok_or_else(|| DbError::BadArgs(format!("node '{name}' has no parent directory")))?
            .to_path_buf(),
    };
    let new_path = father_dir.join(&new_name);
    if new_path == old_path {
        return Ok(true); // already where it was asked to be
    }
    // Moving a node beneath itself would detach the whole subtree from the root.
    if father_dir.starts_with(&old_path) {
        return Err(DbError::BadArgs(format!("cannot move '{name}' into its own subtree")).into());
    }
    // Names are the global key, so a rename onto a name used anywhere else in
    // the tree would make both nodes unresolvable.
    if new_name != name && resolve_node(cfg, &new_name)?.is_some() {
        return Err(DbError::Exists(format!("node '{new_name}' already exists")).into());
    }
    if new_path.symlink_metadata().is_ok() {
        if !replace {
            return Err(
                DbError::Exists(format!("'{}' already exists", new_path.display())).into(),
            );
        }
        // Trash rather than delete: the destination is recoverable, unlike the
        // `rm -rf` the legacy job mover used for the same situation.
        store::move_to_trash(cfg, &new_path)?;
    }

    // Resolve the containers BEFORE the rename — one of them may itself live
    // inside the moving subtree, in which case its directory relocates too and
    // its post-rename path can only be derived from the old one.
    let containers: Vec<(String, PathBuf)> = links_of(cfg, &name)?
        .into_iter()
        .filter_map(|c| {
            resolve_node(cfg, &c)
                .ok()
                .flatten()
                .map(|cr| (c, PathBuf::from(cr.path)))
        })
        .collect();

    fs::rename(&old_path, &new_path).map_err(DbError::from)?;

    for (_, cpath) in &containers {
        let cdir = relocate(cpath, &old_path, &new_path);
        repoint_link(&cdir, &name, &new_name, &new_path);
    }

    let container_names: Vec<&str> = containers.iter().map(|(c, _)| c.as_str()).collect();
    notify_daemon(
        cfg,
        ipc::msg_move(
            &name,
            &new_name,
            &rel_of(cfg, &old_path),
            &rel_of(cfg, &new_path),
            &container_names,
        ),
    );

    cache::invalidate(&name);
    cache::invalidate(&new_name);
    cache::neg_remove(&new_name);
    // Every descendant's cached path just changed. The walk snapshot is only
    // consulted in fallback mode, and dropping it is both cheaper and safer than
    // trying to rewrite a subtree's worth of entries in place.
    cache::snap_invalidate();
    metrics::record_move();
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
    // Why the pre-decoded image path is or is not being used. "abi-mismatch"
    // means the MINIT guard rejected this PHP build: reads still work, they
    // just parse the raw bytes.
    ins_str(
        &mut ht,
        "image",
        if !cfg.image {
            "off"
        } else if IMAGE_ABI_OK.load(std::sync::atomic::Ordering::Relaxed) {
            "on"
        } else {
            "abi-mismatch"
        },
    )?;
    ins_str(
        &mut ht,
        "zero_copy",
        if cfg.zero_copy { "on" } else { "off" },
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
        ins_long("img_bytes", h.img_bytes.load(Relaxed) as i64)?;
    }
    // Live counters from the shared-memory arena (absent when metrics are off).
    if let Some(s) = metrics::snapshot() {
        let mut ins_cnt = |k: &'static str, v: u64| -> Result<(), DbError> {
            let mut z = Zval::new();
            z.set_long(v as i64);
            ht.insert(ArrayKey::Str(k), z)
                .map_err(|e| DbError::Io(format!("array conversion: {e}")))
        };
        ins_cnt("img_serves", s.img_serves)?;
        ins_cnt("img_absent", s.img_absent)?;
        ins_cnt("img_invalid", s.img_invalid)?;
        ins_cnt("seg_pins", s.seg_pins)?;
        ins_cnt("seg_pin_max", s.seg_pin_max)?;
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
        ins_cnt("uds_failure_unix", s.uds_failure_unix)?;
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
        ins_cnt("moves", s.moves)?;
        ins_cnt("doc_deletes", s.doc_deletes)?;
        ins_cnt("raw_writes", s.raw_writes)?;
        ins_cnt("stale_hits", s.stale_hits)?;
        ins_cnt("stale_unconfirmed", s.stale_unconfirmed)?;
        ins_cnt("snap_walks", s.snap_walks)?;
        ins_cnt("snap_walk_ns", s.snap_walk_ns)?;
        ins_cnt("snap_walk_ns_max", s.snap_walk_ns_max)?;
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

/// Does the PHP we are loaded into match the ABI `php_abi.rs` encodes?
///
/// The image hands the engine ready-made `zend_string`s out of shared memory,
/// so a layout or hash mismatch is not a wrong answer — it is a crash. This
/// runs once at MINIT and, on any doubt, disables the image path (reads still
/// work by parsing the raw bytes). It never fails MINIT: the extension must
/// keep serving even on a PHP build we do not recognise.
fn check_php_abi() -> bool {
    use ext_php_rs::ffi::{zend_refcounted_h, zend_string, GC_IMMUTABLE};
    use std::mem::{offset_of, size_of};

    let layout_ok = size_of::<zend_refcounted_h>() == 8
        && offset_of!(zend_string, gc) == php_abi::ZS_OFF_GC
        && offset_of!(zend_string, h) == php_abi::ZS_OFF_H
        && offset_of!(zend_string, len) == php_abi::ZS_OFF_LEN
        && offset_of!(zend_string, val) == php_abi::ZS_OFF_VAL
        && GC_IMMUTABLE == php_abi::GC_IMMUTABLE
        && unsafe { ext_php_rs::ffi::zend_string_init_interned }.is_some();

    layout_ok && hash_selftest()
}

/// Ask the real engine to hash a corpus and compare against our port.
///
/// This is the check that actually earns its keep: `zend_inline_hash_func`
/// depends on the target's `char` signedness and ORs in the top bit, and both
/// mistakes fail *silently* — a wrong hash makes a key that `foreach` can see
/// but `array_key_exists()` denies. Building a string with `h = 0` and letting
/// PHP fill it in compares us against the exact binary we are loaded into.
fn hash_selftest() -> bool {
    let mut corpus: Vec<Vec<u8>> = Vec::new();
    for len in 0..18usize {
        corpus.push((0..len).map(|i| b'a' + (i % 26) as u8).collect());
        corpus.push((0..len).map(|i| 0x80u8.wrapping_add(i as u8)).collect());
    }
    corpus.push(b"title".to_vec());
    corpus.push(b"a\0b".to_vec());

    for bytes in corpus {
        // A fresh (non-interned) string starts with h == 0. `zend_string_hash_val`
        // is a static inline (not an exported symbol), so force the engine to
        // compute and cache the hash the same way any array write would: insert
        // the string as a hash-table key, then read `h` back out of it.
        let zs = ZendStr::new(&bytes, false);
        let raw = zs.into_raw();
        let engine = unsafe {
            let mut ht = ZendHashTable::new();
            let ok = ht.insert(ArrayKey::ZendString(&*raw), ()).is_ok();
            let h = (*raw).h;
            drop(ht);
            if !ok {
                ext_php_rs::ffi::ext_php_rs_zend_string_release(raw);
                return false;
            }
            h
        };
        unsafe { ext_php_rs::ffi::ext_php_rs_zend_string_release(raw) };
        if engine == 0 || engine != php_abi::djbx33a(&bytes) {
            return false;
        }
    }
    true
}

/// Runs after `zend_deactivate()` has torn the executor down, which is the only
/// point at which no live zval can still point into a pinned mapping.
extern "C" fn post_deactivate() -> i32 {
    request_cleanup();
    0
}

fn startup(_ty: i32, module_number: i32) -> i32 {
    IMAGE_ABI_OK.store(check_php_abi(), std::sync::atomic::Ordering::Relaxed);
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
        IniEntryDef::new("quanta_db.image".into(), String::new(), &IniEntryPermission::All),
        IniEntryDef::new(
            "quanta_db.image_max_doc_kb".into(),
            "256".into(),
            &IniEntryPermission::All,
        ),
        IniEntryDef::new(
            "quanta_db.zero_copy".into(),
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
        .post_deactivate_function(post_deactivate)
}
