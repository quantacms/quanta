//! Derived-data path computation, shared verbatim by the extension and the
//! standalone binaries (`qdbd`, `qdbstat`) so all resolve the same per-root home.
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

/// Home of the data segments. tmpfs when available so "all data in RAM" is
/// literal; the `<base>` fallback still works (page-cache-backed) but incurs
/// dirty-page writeback. Existence-only test: the daemon and every PHP worker
/// must deterministically pick the SAME directory.
pub fn shm_dir(base: &Path) -> PathBuf {
    let dev_shm = Path::new("/dev/shm");
    if dev_shm.is_dir() {
        // Reuse the per-root hash segment of `base` for the tmpfs home.
        if let Some(hash) = base.file_name() {
            return dev_shm.join("quanta_db").join(hash);
        }
    }
    base.join("shm")
}

/// The qdbd daemon's unix socket.
pub fn socket_path(base: &Path) -> PathBuf {
    base.join("qdbd.sock")
}

pub fn metrics_path(base: &Path) -> PathBuf {
    base.join("metrics.shm")
}
