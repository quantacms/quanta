//! Derived-data path computation, shared verbatim by the extension (`config.rs`)
//! and the standalone `qdbstat` binary so both resolve the same per-root home.
//!
//! `std::hash::DefaultHasher` uses fixed keys, so the same canonical root hashes
//! to the same directory across processes and across the `.so`/bin boundary.
#![allow(dead_code)]

use std::collections::hash_map::DefaultHasher;
use std::hash::{Hash, Hasher};
use std::io;
use std::path::{Path, PathBuf};

/// `<system tmp>/quanta_db/<16-hex hash of the canonical root>`.
pub fn derive_base(root: &Path) -> io::Result<PathBuf> {
    let root = root.canonicalize()?;
    let mut h = DefaultHasher::new();
    root.hash(&mut h);
    Ok(std::env::temp_dir()
        .join("quanta_db")
        .join(format!("{:016x}", h.finish())))
}

pub fn index_path(base: &Path) -> PathBuf {
    base.join("index.sqlite")
}

pub fn metrics_path(base: &Path) -> PathBuf {
    base.join("metrics.shm")
}
