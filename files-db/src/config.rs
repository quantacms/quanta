use std::path::{Path, PathBuf};

use crate::paths;

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum VerifyReads {
    Always,
    Never,
}

pub struct Config {
    pub root: PathBuf,
    pub index_path: PathBuf,
    pub lock_dir: PathBuf,
    pub trashbin_dir: PathBuf,
    pub lock_timeout_ms: u64,
    pub verify_reads: VerifyReads,
    /// TTL (ms) for the per-process negative cache of known-absent names; a
    /// miss within this window skips the full-docroot self-heal walk. 0 disables.
    pub neg_cache_ms: u64,
    pub metrics: bool,
    pub metrics_path: PathBuf,
}

impl Config {
    /// True for paths of derived data that must never be treated as nodes
    /// when they happen to live inside the data root.
    pub fn is_derived_path(&self, p: &Path) -> bool {
        let index_dir = self.index_path.parent();
        Some(p) == index_dir || p == self.lock_dir || p == self.trashbin_dir
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

    // Default home for derived data: <system tmp>/quanta_db/<hash of root>.
    let base = paths::derive_base(&root).map_err(|e| format!("cannot derive data dir: {e}"))?;

    let index_path = setting("quanta_db.index_path", "QUANTA_DB_INDEX_PATH")
        .map(PathBuf::from)
        .unwrap_or_else(|| paths::index_path(&base));
    let lock_dir = setting("quanta_db.lock_dir", "QUANTA_DB_LOCK_DIR")
        .map(PathBuf::from)
        .unwrap_or_else(|| base.join("locks"));
    let trashbin_dir = setting("quanta_db.trashbin_dir", "QUANTA_DB_TRASHBIN_DIR")
        .map(PathBuf::from)
        .unwrap_or_else(|| base.join("trashbin"));
    let lock_timeout_ms = setting("quanta_db.lock_timeout_ms", "QUANTA_DB_LOCK_TIMEOUT_MS")
        .and_then(|s| s.parse().ok())
        .unwrap_or(5000);
    let verify_reads = match setting("quanta_db.verify_reads", "QUANTA_DB_VERIFY_READS").as_deref()
    {
        Some("never") => VerifyReads::Never,
        _ => VerifyReads::Always,
    };
    // Negative-cache TTL: default 30s. A missing name (never in the index) would
    // otherwise walk the whole docroot on every request; caching the "absent"
    // verdict collapses that to one walk per name per worker per TTL. A stale
    // verdict is harmless (the extension returns NULL and the caller's legacy
    // `find` still locates a node created out of band), so the window is
    // generous; lower it if legacy writers create-then-read within the window.
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
        index_path,
        lock_dir,
        trashbin_dir,
        lock_timeout_ms,
        verify_reads,
        neg_cache_ms,
        metrics,
        metrics_path,
    })
}
