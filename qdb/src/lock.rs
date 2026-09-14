use std::fs::{File, OpenOptions};
use std::os::unix::io::AsRawFd;
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
    std::fs::create_dir_all(&cfg.lock_dir)?;
    let path = cfg.lock_dir.join(format!("{name}.lock"));
    let file = OpenOptions::new().create(true).write(true).open(&path)?;
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

impl Drop for NodeLock {
    fn drop(&mut self) {
        unsafe { libc::flock(self.file.as_raw_fd(), libc::LOCK_UN) };
    }
}
