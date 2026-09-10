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
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

/// One page is far larger than the struct; keeps the mapping simple.
const SIZE: usize = 4096;
/// "QDBSTAT\0" — layout sentinel; a reader that sees a different value bails.
const MAGIC: u64 = 0x0054_4154_5342_4451;
const VERSION: u32 = 10;

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

    // v4: SHM data segment control plane + counters. `data_epoch` is the
    // active segment number published by qdbd (0 = none); `gen_counter` is the
    // shared node-generation allocator (survives daemon restarts within a pod,
    // so long-lived workers' generation-keyed caches stay monotonic).
    pub data_epoch: AtomicU64,
    pub gen_counter: AtomicU64,
    pub daemon_pid: AtomicU64,
    pub uds_notifies: AtomicU64,
    pub uds_failures: AtomicU64,
    pub shm_hits: AtomicU64,    // node lookups served from the data segment
    pub shm_remaps: AtomicU64,  // segment (re)mappings after an epoch flip
    pub shm_invalid: AtomicU64, // lookups that failed validation -> fallback
    pub fallback_reads: AtomicU64, // doc reads served in fallback mode

    // v5: query volume by op kind (read/write/delete were already counted; the
    // listing/relation ops were not) + peak (worst-seen) read/write latency.
    pub children_ops: AtomicU64,
    pub find_ops: AtomicU64,
    pub count_ops: AtomicU64,
    pub links_ops: AtomicU64,
    pub link_ops: AtomicU64,
    pub unlink_ops: AtomicU64,
    pub read_ns_max: AtomicU64,
    pub write_ns_max: AtomicU64,
    // -- v6: pre-decoded image path (append only, never reorder) ------------
    /// Documents served from the pre-decoded image (no JSON parse at all).
    pub img_serves: AtomicU64,
    /// Reads where the record carried no image (images off, oversize, corrupt).
    pub img_absent: AtomicU64,
    /// Images that failed validation — always a fallback, never a fault.
    pub img_invalid: AtomicU64,
    /// Segment mappings pinned for a request so zvals may point into them.
    pub seg_pins: AtomicU64,
    /// Requests that hit the pin cap and fell back to copying.
    pub seg_pin_max: AtomicU64,
    // -- v7: the rest of the write surface (append only, never reorder) -----
    /// Node relocations (`move`): new father and/or new name.
    pub moves: AtomicU64,
    /// Single-language document removals (`deleteDoc`) that removed a file.
    pub doc_deletes: AtomicU64,
    /// Writes that stored caller-supplied bytes verbatim (`putRaw`). Counted in
    /// `writes` too — this is the subset that skipped re-serialization.
    pub raw_writes: AtomicU64,
    // -- v8: recency for the notify-failure signal (append only) ------------
    /// Unix seconds of the most recent failed daemon notify (0 = never).
    /// `uds_failures` counts episodes for the life of the pod, which cannot say
    /// whether one is still happening; graded on its own it pins a long-since
    /// healed pod to DEGRADED forever. Unlike the read counters it also cannot
    /// be graded as a share — its denominator is notify *attempts*, a handful
    /// of writes rather than 100k reads — so `qdbstat` grades it on *when*.
    pub uds_failure_unix: AtomicU64,
    // -- v9: the degraded fast path (append only) ---------------------------
    /// Lookups answered from the last published segment while the daemon was
    /// NOT coherent, and confirmed on disk. Each one is a whole-tree walk that
    /// did not happen.
    pub stale_hits: AtomicU64,
    /// Stale-segment hits the filesystem did not confirm, so the lookup fell
    /// through to the walk. Read as a share of `stale_hits + stale_unconfirmed`
    /// this is how far the segment has drifted from the tree.
    pub stale_unconfirmed: AtomicU64,
    // -- v10: what the degraded path actually COSTS (append only) -----------
    // The fallback path's cost is walks and nothing else: every other step it
    // takes is a hash lookup against a map already in this worker's heap. So
    // these three are the only counters that can distinguish "degraded and
    // cheap" from "degraded and melting", and without them the cost of a
    // fallback lookup is unassertable -- a test can time a call, but timing is
    // the flaky half; the walk COUNT is the contract.
    /// Full docroot walks this worker has paid for (`cache::snap_build`),
    /// whether from a TTL refresh or a forced self-heal.
    pub snap_walks: AtomicU64,
    /// Nanoseconds spent inside those walks. Against `snap_walks` this is the
    /// per-walk cost the adaptive TTL is derived from.
    pub snap_walk_ns: AtomicU64,
    /// The most expensive single walk, in nanoseconds. A request that blocks
    /// on one walk blocks for roughly this long, which an average hides.
    pub snap_walk_ns_max: AtomicU64,
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
    pub data_epoch: u64,
    pub gen_counter: u64,
    pub daemon_pid: u64,
    pub uds_notifies: u64,
    pub uds_failures: u64,
    pub shm_hits: u64,
    pub shm_remaps: u64,
    pub shm_invalid: u64,
    pub fallback_reads: u64,
    pub children_ops: u64,
    pub find_ops: u64,
    pub count_ops: u64,
    pub links_ops: u64,
    pub link_ops: u64,
    pub unlink_ops: u64,
    pub read_ns_max: u64,
    pub write_ns_max: u64,
    pub img_serves: u64,
    pub img_absent: u64,
    pub img_invalid: u64,
    pub seg_pins: u64,
    pub seg_pin_max: u64,
    pub moves: u64,
    pub doc_deletes: u64,
    pub raw_writes: u64,
    pub uds_failure_unix: u64,
    pub stale_hits: u64,
    pub stale_unconfirmed: u64,
    pub snap_walks: u64,
    pub snap_walk_ns: u64,
    pub snap_walk_ns_max: u64,
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
            data_epoch: self.data_epoch.load(REL),
            gen_counter: self.gen_counter.load(REL),
            daemon_pid: self.daemon_pid.load(REL),
            uds_notifies: self.uds_notifies.load(REL),
            uds_failures: self.uds_failures.load(REL),
            shm_hits: self.shm_hits.load(REL),
            shm_remaps: self.shm_remaps.load(REL),
            shm_invalid: self.shm_invalid.load(REL),
            fallback_reads: self.fallback_reads.load(REL),
            children_ops: self.children_ops.load(REL),
            find_ops: self.find_ops.load(REL),
            count_ops: self.count_ops.load(REL),
            links_ops: self.links_ops.load(REL),
            link_ops: self.link_ops.load(REL),
            unlink_ops: self.unlink_ops.load(REL),
            read_ns_max: self.read_ns_max.load(REL),
            write_ns_max: self.write_ns_max.load(REL),
            img_serves: self.img_serves.load(REL),
            img_absent: self.img_absent.load(REL),
            img_invalid: self.img_invalid.load(REL),
            seg_pins: self.seg_pins.load(REL),
            seg_pin_max: self.seg_pin_max.load(REL),
            moves: self.moves.load(REL),
            doc_deletes: self.doc_deletes.load(REL),
            raw_writes: self.raw_writes.load(REL),
            uds_failure_unix: self.uds_failure_unix.load(REL),
            stale_hits: self.stale_hits.load(REL),
            stale_unconfirmed: self.stale_unconfirmed.load(REL),
            snap_walks: self.snap_walks.load(REL),
            snap_walk_ns: self.snap_walk_ns.load(REL),
            snap_walk_ns_max: self.snap_walk_ns_max.load(REL),
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
        let ns = elapsed.as_nanos() as u64;
        m.reads.fetch_add(1, REL);
        m.read_ns.fetch_add(ns, REL);
        m.read_ns_max.fetch_max(ns, REL);
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

/// A lookup answered from the last published segment while the daemon was not
/// coherent (a saved docroot walk).
pub fn stale_hit() {
    if let Some(m) = arena() {
        m.stale_hits.fetch_add(1, REL);
    }
}

/// A stale-segment hit whose path no longer exists, so the lookup fell through.
pub fn stale_unconfirmed() {
    if let Some(m) = arena() {
        m.stale_unconfirmed.fetch_add(1, REL);
    }
}

/// A full docroot walk finished, having cost `ns` nanoseconds.
///
/// Recorded where the walk is built rather than where it is consumed, so a walk
/// counts once no matter how many lookups it goes on to answer -- which is the
/// whole point: the ratio of lookups to walks is what says whether the fallback
/// path is amortising its cost or paying it per name.
pub fn snap_walk(ns: u64) {
    if let Some(m) = arena() {
        m.snap_walks.fetch_add(1, REL);
        m.snap_walk_ns.fetch_add(ns, REL);
        m.snap_walk_ns_max.fetch_max(ns, REL);
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

/// A daemon is treated as flapping once it has dropped coherence this many
/// times inside `FLAP_WINDOW`. Deliberately above what the test suite can
/// produce: `07_daemon_failure.php` reaches three drops (the `fresh_env`
/// restart, the SIGTERM, and the SIGKILL whose failed ack poisons coherence),
/// and a clean stop/restart must always recover instantly.
const FLAP_EPISODES: u32 = 5;

/// Window over which coherence drops are counted toward the flap verdict.
const FLAP_WINDOW: Duration = Duration::from_secs(60);

/// Once flapping, require this much *continuous* coherence before trusting the
/// index again, so a worker latches into fallback instead of being dragged back
/// onto a segment that is about to disappear.
///
/// Held under the harness's 10s coherence deadline (`tests/php/_harness.php`,
/// `qdb_daemon_start`) so that even if a test ever did trip the flap verdict,
/// the wait still succeeds rather than failing the suite. That bounds how hard
/// this can damp: it fully covers the pathological respawn loop (sub-second
/// lifetimes) and only partly covers the slower one.
const FLAP_SETTLE: Duration = Duration::from_secs(8);

thread_local! {
    /// Per-worker view of daemon stability. Not shared state: each worker forms
    /// its own verdict from what it observes, so there is no arena layout
    /// change and no cross-process coordination to get wrong.
    static FLAP: std::cell::RefCell<FlapState> = const {
        std::cell::RefCell::new(FlapState { episodes: 0, first_seen: None, last_drop: None, was_coherent: true })
    };
}

struct FlapState {
    episodes: u32,
    first_seen: Option<Instant>,
    last_drop: Option<Instant>,
    was_coherent: bool,
}

/// True when a healthy watcher is keeping the index authoritative: the coherent
/// flag is set and the heartbeat is fresh. When false, callers must fall back to
/// the filesystem self-heal (fs_search). Never blocks and never errors.
///
/// Damped against a *flapping* daemon. The raw flag is re-asserted by qdbd on
/// every ~1s loop tick, so a daemon that cores every ~12s re-arms coherence
/// between each crash and drags every worker back onto a segment it is about to
/// lose -- paying the full cost of both modes and settling into neither. After
/// `FLAP_EPISODES` drops within `FLAP_WINDOW` this reports false until the
/// daemon has held coherence for `FLAP_SETTLE`. A single restart is unaffected.
pub fn index_coherent() -> bool {
    let raw = match arena() {
        Some(m) => {
            m.watch_coherent.load(REL) == 1
                && now_unix().saturating_sub(m.watch_heartbeat_unix.load(REL)) <= COHERENCE_MAX_STALE
        }
        None => false,
    };
    let now = Instant::now();
    FLAP.with(|c| flap_verdict(&mut c.borrow_mut(), raw, now))
}

/// The damping state machine, split out so it can be tested without an arena.
///
/// `raw` is what the shared flag says; the return value is what the caller
/// should believe. Time is injected so the tests can step it deterministically.
fn flap_verdict(f: &mut FlapState, raw: bool, now: Instant) -> bool {
    // Age out a stale verdict so a daemon that has since settled is not held in
    // fallback forever.
    if let Some(first) = f.first_seen {
        if now.saturating_duration_since(first) > FLAP_WINDOW && !raw {
            f.episodes = 0;
            f.first_seen = None;
        }
    }

    if !raw {
        if f.was_coherent {
            // A falling edge: one episode. Counting edges rather than samples is
            // what keeps a long outage from reading as a flap.
            f.episodes = f.episodes.saturating_add(1);
            f.first_seen.get_or_insert(now);
        }
        f.was_coherent = false;
        f.last_drop = Some(now);
        return false;
    }

    f.was_coherent = true;

    // Note this does NOT re-check `first_seen` against `FLAP_WINDOW`. The window
    // bounds how drops are *counted*, not how long a verdict already reached
    // survives: expiring it here would release a latched worker back onto the
    // segment after however much of `FLAP_SETTLE` happened to fall inside the
    // window, which is not the "continuous coherence" the settle promises. The
    // verdict is still bounded — the settle clause below always clears it after
    // `FLAP_SETTLE`, and the aging branch above clears it during a long outage.
    let flapping = f.episodes >= FLAP_EPISODES;
    if flapping {
        if let Some(d) = f.last_drop {
            if now.saturating_duration_since(d) < FLAP_SETTLE {
                return false;
            }
        }
        // Settled long enough: clear the verdict and trust it again.
        f.episodes = 0;
        f.first_seen = None;
    }
    true
}

#[cfg(test)]
mod flap_tests {
    use super::*;

    fn fresh() -> FlapState {
        FlapState {
            episodes: 0,
            first_seen: None,
            last_drop: None,
            was_coherent: true,
        }
    }

    #[test]
    fn healthy_daemon_is_trusted_on_the_first_call() {
        // A worker that has never seen a drop must not start in fallback.
        let mut f = fresh();
        assert!(flap_verdict(&mut f, true, Instant::now()));
    }

    #[test]
    fn a_few_restarts_recover_instantly() {
        // The shape `07_daemon_failure.php` produces: the fresh_env restart, the
        // SIGTERM, and the SIGKILL whose failed ack poisons coherence. Each must
        // be trusted again immediately, or the harness's 10s wait fails.
        let t0 = Instant::now();
        let mut f = fresh();
        for i in 0..3 {
            let drop = t0 + Duration::from_secs(i * 2);
            assert!(!flap_verdict(&mut f, false, drop));
            assert!(
                flap_verdict(&mut f, true, drop + Duration::from_millis(50)),
                "restart {i} must recover instantly"
            );
        }
    }

    #[test]
    fn a_crash_loop_latches_into_fallback() {
        let t0 = Instant::now();
        let mut f = fresh();
        let mut t = 0;
        for _ in 0..FLAP_EPISODES {
            flap_verdict(&mut f, false, t0 + Duration::from_secs(t));
            t += 1;
            flap_verdict(&mut f, true, t0 + Duration::from_secs(t));
            t += 1;
        }
        let drop = t0 + Duration::from_secs(t);
        flap_verdict(&mut f, false, drop);
        assert!(!flap_verdict(&mut f, true, drop + Duration::from_secs(1)));
        assert!(!flap_verdict(&mut f, true, drop + FLAP_SETTLE - Duration::from_secs(1)));
        assert!(flap_verdict(&mut f, true, drop + FLAP_SETTLE));
    }

    #[test]
    fn the_settle_is_not_cut_short_by_the_counting_window() {
        // A burst of drops, then a long outage, then recovery so late that the
        // 60s counting window expires mid-settle. The worker must still serve
        // the full FLAP_SETTLE of continuous coherence: the window governs how
        // drops are counted, not how long an existing verdict lasts.
        let t0 = Instant::now();
        let mut f = fresh();
        for i in 0..FLAP_EPISODES {
            flap_verdict(&mut f, false, t0 + Duration::from_secs(u64::from(i)));
            flap_verdict(&mut f, true, t0 + Duration::from_millis(u64::from(i) * 1000 + 500));
        }
        // Daemon stays down until just inside the window, then comes back.
        let back = t0 + Duration::from_secs(58);
        assert!(!flap_verdict(&mut f, false, back - Duration::from_millis(1)));
        assert!(!flap_verdict(&mut f, true, back));
        // t0+61 is past FLAP_WINDOW but only 3s of continuous coherence.
        assert!(
            !flap_verdict(&mut f, true, t0 + Duration::from_secs(61)),
            "window expiry must not release a latched worker early"
        );
        // Once the settle really is served, trust returns.
        assert!(flap_verdict(&mut f, true, back + FLAP_SETTLE));
    }

    #[test]
    fn settle_stays_under_the_harness_deadline() {
        // tests/php/_harness.php gives qdb_daemon_start 10s to see coherence.
        // Damping must never be able to outlast that, even if a test trips it.
        assert!(FLAP_SETTLE < Duration::from_secs(10));
    }

    #[test]
    fn a_settled_daemon_is_trusted_again() {
        let t0 = Instant::now();
        let mut f = fresh();
        let mut t = 0;
        for _ in 0..FLAP_EPISODES {
            flap_verdict(&mut f, false, t0 + Duration::from_secs(t));
            t += 1;
            flap_verdict(&mut f, true, t0 + Duration::from_secs(t));
            t += 1;
        }
        assert!(flap_verdict(&mut f, true, t0 + Duration::from_secs(t + 300)));
    }
}

/// A miss answered as authoritative-absent (no docroot walk).
pub fn authoritative_miss() {
    if let Some(m) = arena() {
        m.authoritative_misses.fetch_add(1, REL);
    }
}

// ---------------------------------------------------------------------------
// SHM data segment control plane (written by qdbd, read by the extension)
// ---------------------------------------------------------------------------

/// The active data-segment epoch (0 = no segment published).
pub fn data_epoch() -> u64 {
    arena().map(|m| m.data_epoch.load(Ordering::Acquire)).unwrap_or(0)
}

/// Daemon: publish a new active segment. Release-ordered so a reader that
/// observes the epoch also observes the fully-written segment file header.
pub fn set_data_epoch(epoch: u64) {
    if let Some(m) = arena() {
        m.data_epoch.store(epoch, Ordering::Release);
    }
}

/// Allocate the next node generation from the shared counter. Falls back to a
/// process-local counter when the arena is unavailable (still monotonic within
/// the process, which is all fallback mode needs).
pub fn next_generation() -> u64 {
    static LOCAL: AtomicU64 = AtomicU64::new(1);
    match arena() {
        Some(m) => m.gen_counter.fetch_add(1, REL) + 1,
        None => LOCAL.fetch_add(1, REL) + 1,
    }
}

pub fn set_daemon_pid(pid: u64) {
    if let Some(m) = arena() {
        m.daemon_pid.store(pid, REL);
    }
}

pub fn uds_notify() {
    if let Some(m) = arena() {
        m.uds_notifies.fetch_add(1, REL);
    }
}

pub fn uds_failure() {
    if let Some(m) = arena() {
        m.uds_failures.fetch_add(1, REL);
        m.uds_failure_unix.store(now_unix(), REL);
    }
}

pub fn shm_hit() {
    if let Some(m) = arena() {
        m.shm_hits.fetch_add(1, REL);
    }
}

pub fn shm_remap() {
    if let Some(m) = arena() {
        m.shm_remaps.fetch_add(1, REL);
    }
}

pub fn img_serve() {
    if let Some(m) = arena() {
        m.img_serves.fetch_add(1, REL);
    }
}

pub fn img_absent() {
    if let Some(m) = arena() {
        m.img_absent.fetch_add(1, REL);
    }
}

pub fn img_invalid() {
    if let Some(m) = arena() {
        m.img_invalid.fetch_add(1, REL);
    }
}

pub fn seg_pin() {
    if let Some(m) = arena() {
        m.seg_pins.fetch_add(1, REL);
    }
}

pub fn seg_pin_max() {
    if let Some(m) = arena() {
        m.seg_pin_max.fetch_add(1, REL);
    }
}

pub fn shm_invalid() {
    if let Some(m) = arena() {
        m.shm_invalid.fetch_add(1, REL);
    }
}

pub fn fallback_read() {
    if let Some(m) = arena() {
        m.fallback_reads.fetch_add(1, REL);
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
        let ns = elapsed.as_nanos() as u64;
        m.writes.fetch_add(1, REL);
        m.write_ns.fetch_add(ns, REL);
        m.write_ns_max.fetch_max(ns, REL);
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

// Query volume by op kind (v5). Reads/writes/deletes are counted elsewhere;
// these cover the listing/relation queries so qdbstat can show the op mix.
pub fn record_children() {
    if let Some(m) = arena() {
        m.children_ops.fetch_add(1, REL);
    }
}

pub fn record_find() {
    if let Some(m) = arena() {
        m.find_ops.fetch_add(1, REL);
    }
}

pub fn record_count() {
    if let Some(m) = arena() {
        m.count_ops.fetch_add(1, REL);
    }
}

pub fn record_links() {
    if let Some(m) = arena() {
        m.links_ops.fetch_add(1, REL);
    }
}

pub fn record_link() {
    if let Some(m) = arena() {
        m.link_ops.fetch_add(1, REL);
    }
}

pub fn record_unlink() {
    if let Some(m) = arena() {
        m.unlink_ops.fetch_add(1, REL);
    }
}

// The rest of the write surface (v7).
pub fn record_move() {
    if let Some(m) = arena() {
        m.moves.fetch_add(1, REL);
    }
}

pub fn record_doc_delete() {
    if let Some(m) = arena() {
        m.doc_deletes.fetch_add(1, REL);
    }
}

pub fn record_raw_write() {
    if let Some(m) = arena() {
        m.raw_writes.fetch_add(1, REL);
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
