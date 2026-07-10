//! Shared-memory counter arena for `qdbstat` (a varnishstat-style monitor).
//!
//! Each PHP worker process maps the same file (`MAP_SHARED`) and bumps atomic
//! counters on the hot paths; the `qdbstat` binary maps it read-only and renders
//! live rates/latency. The file lives with the rest of the derived data
//! (`<base>/metrics.shm`) so it is per-root and rebuildable.
//!
//! This module is std + libc only (no ext-php-rs) so `src/bin/qdbstat.rs` can
//! reuse the exact struct layout and `map_file` via `#[path]` include.
//!
//! Instrumentation must NEVER break the DB: if the mapping cannot be created the
//! global handle is `None` and every `record_*` call becomes a no-op.
#![allow(dead_code)]

use std::ffi::CString;
use std::io;
use std::os::unix::ffi::OsStrExt;
use std::path::Path;
use std::ptr;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::OnceLock;
use std::time::{Duration, SystemTime, UNIX_EPOCH};

/// One page is far larger than the struct; keeps the mapping simple.
const SIZE: usize = 4096;
/// "QDBSTAT\0" — layout sentinel; a reader that sees a different value bails.
const MAGIC: u64 = 0x0054_4154_5342_4451;
const VERSION: u32 = 3;

const REL: Ordering = Ordering::Relaxed;

/// The index is trusted as authoritative only while a watcher heartbeat is at
/// most this old (seconds). Past it, `resolve_node` falls back to fs_search.
const COHERENCE_MAX_STALE: u64 = 5;

/// Fixed C layout shared across processes. Only append fields (and bump
/// `VERSION`) — never reorder or remove, or existing mappings misread.
#[repr(C)]
pub struct Metrics {
    pub magic: AtomicU64,
    pub version: u32,
    pub _pad: u32,
    pub started_unix: AtomicU64,

    pub reads: AtomicU64,
    pub read_ns: AtomicU64,
    pub cache_hits: AtomicU64,
    pub cache_misses: AtomicU64,
    pub index_serves: AtomicU64,
    pub file_reads: AtomicU64,
    pub bytes_read: AtomicU64,
    pub fs_heals: AtomicU64,
    pub node_misses: AtomicU64,

    pub writes: AtomicU64,
    pub write_ns: AtomicU64,
    pub bytes_written: AtomicU64,
    pub deletes: AtomicU64,

    pub lock_acquires: AtomicU64,
    pub lock_wait_ns: AtomicU64,
    pub lock_timeouts: AtomicU64,

    pub io_errors: AtomicU64,
    pub corrupt_json: AtomicU64,

    // v2: appended at the end so v1 offsets are unchanged.
    pub neg_hits: AtomicU64,

    // v3: watcher coherence. Written by qdbwatch, read by the extension.
    pub watch_heartbeat_unix: AtomicU64,
    pub watch_epoch: AtomicU64,   // bumped on each full (re)snapshot
    pub watch_coherent: AtomicU64, // 0/1: a healthy watcher owns the index
    pub watch_events: AtomicU64,
    pub watch_resyncs: AtomicU64,
    pub authoritative_misses: AtomicU64, // misses answered without a walk
}

const _: () = assert!(std::mem::size_of::<Metrics>() <= SIZE);

/// A plain, non-atomic copy of every counter, taken at one instant.
#[derive(Clone, Copy, Default)]
pub struct Snapshot {
    pub started_unix: u64,
    pub reads: u64,
    pub read_ns: u64,
    pub cache_hits: u64,
    pub cache_misses: u64,
    pub index_serves: u64,
    pub file_reads: u64,
    pub bytes_read: u64,
    pub fs_heals: u64,
    pub node_misses: u64,
    pub writes: u64,
    pub write_ns: u64,
    pub bytes_written: u64,
    pub deletes: u64,
    pub lock_acquires: u64,
    pub lock_wait_ns: u64,
    pub lock_timeouts: u64,
    pub io_errors: u64,
    pub corrupt_json: u64,
    pub neg_hits: u64,
    pub watch_heartbeat_unix: u64,
    pub watch_epoch: u64,
    pub watch_coherent: u64,
    pub watch_events: u64,
    pub watch_resyncs: u64,
    pub authoritative_misses: u64,
}

impl Metrics {
    pub fn snapshot(&self) -> Snapshot {
        Snapshot {
            started_unix: self.started_unix.load(REL),
            reads: self.reads.load(REL),
            read_ns: self.read_ns.load(REL),
            cache_hits: self.cache_hits.load(REL),
            cache_misses: self.cache_misses.load(REL),
            index_serves: self.index_serves.load(REL),
            file_reads: self.file_reads.load(REL),
            bytes_read: self.bytes_read.load(REL),
            fs_heals: self.fs_heals.load(REL),
            node_misses: self.node_misses.load(REL),
            writes: self.writes.load(REL),
            write_ns: self.write_ns.load(REL),
            bytes_written: self.bytes_written.load(REL),
            deletes: self.deletes.load(REL),
            lock_acquires: self.lock_acquires.load(REL),
            lock_wait_ns: self.lock_wait_ns.load(REL),
            lock_timeouts: self.lock_timeouts.load(REL),
            io_errors: self.io_errors.load(REL),
            corrupt_json: self.corrupt_json.load(REL),
            neg_hits: self.neg_hits.load(REL),
            watch_heartbeat_unix: self.watch_heartbeat_unix.load(REL),
            watch_epoch: self.watch_epoch.load(REL),
            watch_coherent: self.watch_coherent.load(REL),
            watch_events: self.watch_events.load(REL),
            watch_resyncs: self.watch_resyncs.load(REL),
            authoritative_misses: self.authoritative_misses.load(REL),
        }
    }
}

fn now_unix() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0)
}

/// Closes a fd on drop; the mmap stays valid after the fd is gone.
struct FdGuard(libc::c_int);
impl Drop for FdGuard {
    fn drop(&mut self) {
        unsafe { libc::close(self.0) };
    }
}

/// Map the arena file. `create` opens/extends it (writer side); otherwise it must
/// already exist (reader side). Returns a pointer valid for the process lifetime.
///
/// # Safety
/// The returned pointer aliases a shared mapping; only atomic access is sound.
pub unsafe fn map_file(path: &Path, create: bool, writable: bool) -> io::Result<*mut Metrics> {
    let cpath = CString::new(path.as_os_str().as_bytes())
        .map_err(|_| io::Error::new(io::ErrorKind::InvalidInput, "path contains NUL"))?;
    let mut oflag = if writable { libc::O_RDWR } else { libc::O_RDONLY };
    if create {
        oflag |= libc::O_CREAT;
    }
    let fd = libc::open(cpath.as_ptr(), oflag, 0o644);
    if fd < 0 {
        return Err(io::Error::last_os_error());
    }
    let guard = FdGuard(fd);

    if create && libc::ftruncate(fd, SIZE as libc::off_t) != 0 {
        return Err(io::Error::last_os_error());
    }
    let prot = if writable {
        libc::PROT_READ | libc::PROT_WRITE
    } else {
        libc::PROT_READ
    };
    let addr = libc::mmap(ptr::null_mut(), SIZE, prot, libc::MAP_SHARED, fd, 0);
    if addr == libc::MAP_FAILED {
        return Err(io::Error::last_os_error());
    }
    let m = addr as *mut Metrics;

    if create && writable {
        // Initialize the header exactly once across racing workers. flock
        // serializes; `magic` is published last (Release) as the ready marker.
        // A stale file from a previous build (different VERSION => possibly a
        // different layout) is zeroed and rebuilt so its bytes aren't misread.
        libc::flock(fd, libc::LOCK_EX);
        let fresh = (*m).magic.load(Ordering::Acquire) != MAGIC;
        let stale = !fresh && (*m).version != VERSION;
        if fresh || stale {
            if stale {
                (*m).magic.store(0, Ordering::Release);
                ptr::write_bytes(addr as *mut u8, 0, SIZE);
            }
            (*m).version = VERSION;
            (*m).started_unix.store(now_unix(), REL);
            (*m).magic.store(MAGIC, Ordering::Release);
        }
        libc::flock(fd, libc::LOCK_UN);
    }
    drop(guard);
    Ok(m)
}

/// True if the mapping carries a header this build understands.
pub fn is_ready(m: &Metrics) -> bool {
    m.magic.load(Ordering::Acquire) == MAGIC && m.version == VERSION
}

// ---------------------------------------------------------------------------
// Extension-side global handle + record helpers (unused by the bin)
// ---------------------------------------------------------------------------

struct Handle(*mut Metrics);
// Safe: the target is a shared mapping accessed only through atomics.
unsafe impl Send for Handle {}
unsafe impl Sync for Handle {}

static ARENA: OnceLock<Option<Handle>> = OnceLock::new();

/// Resolve the process-wide arena once. Called from `config` after the derived
/// paths are known. A disabled or unmappable arena yields `None` (no-op mode).
pub fn init(path: &Path, enabled: bool) {
    ARENA.get_or_init(|| {
        if !enabled {
            return None;
        }
        if let Some(dir) = path.parent() {
            let _ = std::fs::create_dir_all(dir);
        }
        match unsafe { map_file(path, true, true) } {
            Ok(p) => Some(Handle(p)),
            Err(_) => None,
        }
    });
}

fn arena() -> Option<&'static Metrics> {
    match ARENA.get() {
        Some(Some(h)) => unsafe { h.0.as_ref() },
        _ => None,
    }
}

/// Snapshot the live arena for `QuantaDb::stats()`; `None` when metrics are off.
pub fn snapshot() -> Option<Snapshot> {
    arena().map(Metrics::snapshot)
}

pub fn record_read(elapsed: Duration) {
    if let Some(m) = arena() {
        m.reads.fetch_add(1, REL);
        m.read_ns.fetch_add(elapsed.as_nanos() as u64, REL);
    }
}

pub fn cache_hit() {
    if let Some(m) = arena() {
        m.cache_hits.fetch_add(1, REL);
    }
}

pub fn cache_miss() {
    if let Some(m) = arena() {
        m.cache_misses.fetch_add(1, REL);
    }
}

pub fn index_serve() {
    if let Some(m) = arena() {
        m.index_serves.fetch_add(1, REL);
    }
}

pub fn file_read(bytes: u64) {
    if let Some(m) = arena() {
        m.file_reads.fetch_add(1, REL);
        m.bytes_read.fetch_add(bytes, REL);
    }
}

pub fn fs_heal() {
    if let Some(m) = arena() {
        m.fs_heals.fetch_add(1, REL);
    }
}

pub fn node_miss() {
    if let Some(m) = arena() {
        m.node_misses.fetch_add(1, REL);
    }
}

/// A known-absent lookup served from the negative cache (a saved docroot walk).
pub fn neg_hit() {
    if let Some(m) = arena() {
        m.neg_hits.fetch_add(1, REL);
    }
}

// ---------------------------------------------------------------------------
// Watcher coherence (read by the extension, written by qdbwatch)
// ---------------------------------------------------------------------------

/// True when a healthy watcher is keeping the index authoritative: the coherent
/// flag is set and the heartbeat is fresh. When false, callers must fall back to
/// the filesystem self-heal (fs_search). Never blocks and never errors.
pub fn index_coherent() -> bool {
    match arena() {
        Some(m) => {
            m.watch_coherent.load(REL) == 1
                && now_unix().saturating_sub(m.watch_heartbeat_unix.load(REL)) <= COHERENCE_MAX_STALE
        }
        None => false,
    }
}

/// A miss answered as authoritative-absent (no docroot walk).
pub fn authoritative_miss() {
    if let Some(m) = arena() {
        m.authoritative_misses.fetch_add(1, REL);
    }
}

/// Watcher: publish a heartbeat (call ~every second).
pub fn set_heartbeat() {
    if let Some(m) = arena() {
        m.watch_heartbeat_unix.store(now_unix(), REL);
    }
}

/// Watcher: mark the index authoritative (1) or not (0).
pub fn set_coherent(on: bool) {
    if let Some(m) = arena() {
        m.watch_coherent.store(on as u64, REL);
    }
}

/// Watcher: bump the epoch after a full (re)snapshot.
pub fn bump_epoch() {
    if let Some(m) = arena() {
        m.watch_epoch.fetch_add(1, REL);
    }
}

/// Watcher: count an applied inotify event.
pub fn record_watch_event() {
    if let Some(m) = arena() {
        m.watch_events.fetch_add(1, REL);
    }
}

/// Watcher: count a full resync (periodic or overflow recovery).
pub fn record_resync() {
    if let Some(m) = arena() {
        m.watch_resyncs.fetch_add(1, REL);
    }
}

pub fn record_write(elapsed: Duration, bytes: u64) {
    if let Some(m) = arena() {
        m.writes.fetch_add(1, REL);
        m.write_ns.fetch_add(elapsed.as_nanos() as u64, REL);
        m.bytes_written.fetch_add(bytes, REL);
    }
}

pub fn deleted() {
    if let Some(m) = arena() {
        m.deletes.fetch_add(1, REL);
    }
}

pub fn record_lock(wait: Duration) {
    if let Some(m) = arena() {
        m.lock_acquires.fetch_add(1, REL);
        m.lock_wait_ns.fetch_add(wait.as_nanos() as u64, REL);
    }
}

pub fn lock_timeout() {
    if let Some(m) = arena() {
        m.lock_timeouts.fetch_add(1, REL);
    }
}

pub fn io_error() {
    if let Some(m) = arena() {
        m.io_errors.fetch_add(1, REL);
    }
}

pub fn corrupt_json() {
    if let Some(m) = arena() {
        m.corrupt_json.fetch_add(1, REL);
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn arena_survives_remap() {
        let dir = std::env::temp_dir().join(format!("qdb_metrics_test_{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let path = dir.join("metrics.shm");
        let _ = std::fs::remove_file(&path);

        // Writer maps, bumps counters.
        let w = unsafe { map_file(&path, true, true).unwrap() };
        let m = unsafe { &*w };
        assert!(is_ready(m));
        m.reads.fetch_add(7, REL);
        m.read_ns.fetch_add(1_000, REL);
        m.cache_hits.fetch_add(5, REL);

        // Independent read-only mapping sees the same values.
        let r = unsafe { map_file(&path, false, false).unwrap() };
        let snap = unsafe { (*r).snapshot() };
        assert_eq!(snap.reads, 7);
        assert_eq!(snap.read_ns, 1_000);
        assert_eq!(snap.cache_hits, 5);
        assert!(snap.started_unix > 0);

        let _ = std::fs::remove_file(&path);
        let _ = std::fs::remove_dir(&dir);
    }
}
