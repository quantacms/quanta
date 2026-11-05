use std::fs::{File, OpenOptions};
use std::io;
use std::os::unix::io::AsRawFd;
use std::path::Path;
use std::time::{Duration, Instant};

use crate::config::Config;
use crate::error::DbError;

/// Per-node advisory exclusive lock (contract §5). flock-based: the kernel
/// releases it automatically if the holding process dies, so a crashed
/// worker never wedges a node.
pub struct NodeLock {
    file: File,
}

pub fn acquire(cfg: &Config, name: &str) -> Result<NodeLock, DbError> {
    let path = cfg.lock_dir.join(format!("{name}.lock"));
    let file = open_lock(&cfg.lock_dir, &path)?;
    let start = Instant::now();
    let deadline = start + Duration::from_millis(cfg.lock_timeout_ms);
    loop {
        let r = unsafe { libc::flock(file.as_raw_fd(), libc::LOCK_EX | libc::LOCK_NB) };
        if r == 0 {
            crate::metrics::record_lock(start.elapsed());
            return Ok(NodeLock { file });
        }
        if Instant::now() >= deadline {
            crate::metrics::lock_timeout();
            return Err(DbError::LockTimeout(format!(
                "could not lock node '{name}' within {}ms",
                cfg.lock_timeout_ms
            )));
        }
        std::thread::sleep(Duration::from_millis(5));
    }
}

/// Open a node's lock file, creating it and the lock dir when missing.
///
/// Both are created as the owner of the dir they go in (see `own`), so a root
/// CLI leaves a lock dir and lock files the site's user can still use. A lock
/// file this process may not open for writing (root-owned 0644, left by a root
/// CLI of an older build) is opened read-only instead: flock(2) takes the same
/// exclusive lock through a read-only descriptor, so the node is locked either
/// way.
fn open_lock(dir: &Path, path: &Path) -> io::Result<File> {
    let rw = {
        let _owner = crate::own::of(dir);
        std::fs::create_dir_all(dir)
            .and_then(|()| OpenOptions::new().create(true).write(true).open(path))
    };
    match rw {
        Err(e) if e.kind() == io::ErrorKind::PermissionDenied => File::open(path).map_err(|_| e),
        r => r,
    }
}

impl Drop for NodeLock {
    fn drop(&mut self) {
        unsafe { libc::flock(self.file.as_raw_fd(), libc::LOCK_UN) };
    }
}
