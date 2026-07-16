use std::path::{Path, PathBuf};

use crate::paths;

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum VerifyReads {
    Always,
    Never,
}

pub struct Config {
    pub root: PathBuf,
    /// FNV-1a of the canonical root string; segments carry it so a reader can
    /// never consume a segment built for a different root.
    pub root_hash: u64,
    /// Directory holding the data segments (`data.<epoch>.shm`). Defaults to
    /// tmpfs (`/dev/shm/quanta_db/<hash>`) so "in RAM" is literal.
    pub shm_dir: PathBuf,
    /// The qdbd daemon's unix socket (write notifications).
    pub socket_path: PathBuf,
    pub lock_dir: PathBuf,
    pub trashbin_dir: PathBuf,
    pub lock_timeout_ms: u64,
    /// Budget for waiting on a write ack from the daemon; past it the write
    /// (already durable on disk) proceeds and the process poisons coherence.
    pub write_ack_timeout_ms: u64,
    /// Initial data-segment budget in MB (sparse on tmpfs until touched).
    /// Consumed by the qdbd daemon only — dead code from the cdylib's view.
    #[allow(dead_code)]
    pub shm_size_mb: u64,
    /// Fallback-mode-only: stat-validate file reads. In SHM mode the segment
    /// is authoritative (the daemon's inotify observes external writes).
    pub verify_reads: VerifyReads,
    /// TTL (ms) for the per-process negative cache of known-absent names
    /// (fallback mode); a miss within this window skips the self-heal walk.
    /// 0 disables.
    pub neg_cache_ms: u64,
    /// The metrics arena doubles as the daemon/extension control plane
    /// (coherence heartbeat + active epoch); disabling it forces permanent
    /// fallback mode.
    pub metrics: bool,
    pub metrics_path: PathBuf,
}

impl Config {
    /// True for paths of derived data that must never be treated as nodes
    /// when they happen to live inside the data root.
    pub fn is_derived_path(&self, p: &Path) -> bool {
        p == self.shm_dir
            || Some(p) == self.socket_path.parent()
            || p == self.lock_dir
            || p == self.trashbin_dir
    }
}

/// Build the config from settings resolved by `ini_get` (php.ini, per contract
/// §6) with an environment-variable fallback. `ini_get` is injected so this
/// module stays PHP-free and can be reused by the standalone binaries (which
/// pass `|_| None` — env only). The cdylib passes an `ext-php-rs`-backed getter.
pub fn build_with(ini_get: impl Fn(&str) -> Option<String>) -> Result<Config, String> {
    // php.ini first (non-empty), then the environment variable.
    let setting = |ini: &str, env: &str| -> Option<String> {
        ini_get(ini)
            .filter(|s| !s.is_empty())
            .or_else(|| std::env::var(env).ok().filter(|s| !s.is_empty()))
    };

    let root = setting("quanta_db.root", "QUANTA_DB_ROOT")
        .ok_or("quanta_db.root is not configured (php.ini or QUANTA_DB_ROOT)")?;
    let root = PathBuf::from(root);
    if !root.is_dir() {
        return Err(format!("quanta_db.root is not a directory: {}", root.display()));
    }
    let root = root
        .canonicalize()
        .map_err(|e| format!("cannot resolve quanta_db.root: {e}"))?;
    let root_hash = crate::shm::fnv1a(root.to_string_lossy().as_bytes());

    // Default home for derived data: <system tmp>/quanta_db/<hash of root>.
    let base = paths::derive_base(&root).map_err(|e| format!("cannot derive data dir: {e}"))?;

    // The daemon and every PHP worker MUST compute the same default here, so
    // the preference test is existence-only (deterministic across users).
    let shm_dir = setting("quanta_db.shm_dir", "QUANTA_DB_SHM_DIR")
        .map(PathBuf::from)
        .unwrap_or_else(|| paths::shm_dir(&base));
    let socket_path = setting("quanta_db.socket_path", "QUANTA_DB_SOCKET_PATH")
        .map(PathBuf::from)
        .unwrap_or_else(|| paths::socket_path(&base));
    let lock_dir = setting("quanta_db.lock_dir", "QUANTA_DB_LOCK_DIR")
        .map(PathBuf::from)
        .unwrap_or_else(|| base.join("locks"));
    let trashbin_dir = setting("quanta_db.trashbin_dir", "QUANTA_DB_TRASHBIN_DIR")
        .map(PathBuf::from)
        .unwrap_or_else(|| base.join("trashbin"));
    let lock_timeout_ms = setting("quanta_db.lock_timeout_ms", "QUANTA_DB_LOCK_TIMEOUT_MS")
        .and_then(|s| s.parse().ok())
        .unwrap_or(5000);
    let write_ack_timeout_ms = setting(
        "quanta_db.write_ack_timeout_ms",
        "QUANTA_DB_WRITE_ACK_TIMEOUT_MS",
    )
    .and_then(|s| s.parse().ok())
    .unwrap_or(250);
    let shm_size_mb = setting("quanta_db.shm_size_mb", "QUANTA_DB_SHM_SIZE_MB")
        .and_then(|s| s.parse().ok())
        .unwrap_or(64);
    let verify_reads = match setting("quanta_db.verify_reads", "QUANTA_DB_VERIFY_READS").as_deref()
    {
        Some("never") => VerifyReads::Never,
        _ => VerifyReads::Always,
    };
    // Negative-cache TTL (fallback mode): default 30s. A missing name would
    // otherwise walk the whole docroot on every request; caching the "absent"
    // verdict collapses that to one walk per name per worker per TTL. A stale
    // verdict is harmless (the extension returns NULL and the caller's legacy
    // `find` still locates a node created out of band).
    let neg_cache_ms = setting("quanta_db.neg_cache_ms", "QUANTA_DB_NEG_CACHE_MS")
        .and_then(|s| s.parse().ok())
        .unwrap_or(30_000);
    // Metrics arena is on unless explicitly disabled (off/0/false/no).
    let metrics = !matches!(
        setting("quanta_db.metrics", "QUANTA_DB_METRICS").as_deref(),
        Some("off" | "0" | "false" | "no")
    );
    let metrics_path = setting("quanta_db.metrics_path", "QUANTA_DB_METRICS_PATH")
        .map(PathBuf::from)
        .unwrap_or_else(|| paths::metrics_path(&base));

    Ok(Config {
        root,
        root_hash,
        shm_dir,
        socket_path,
        lock_dir,
        trashbin_dir,
        lock_timeout_ms,
        write_ack_timeout_ms,
        shm_size_mb,
        verify_reads,
        neg_cache_ms,
        metrics,
        metrics_path,
    })
}
