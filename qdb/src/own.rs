//! What a root process creates belongs to the owner of the directory it is
//! created in.
//!
//! The extension normally runs inside php-fpm, as the site's user. A PHP CLI
//! started as root (`kubectl exec <pod> -- php …`, no `su`) loads the same
//! extension, and what it created used to come out root-owned 0755/0644: the
//! lock dir and the lock files in it, the metrics arena, a new node's dir and
//! document, the trashbin dirs. The site's user can then neither create entries
//! in those dirs nor open those files for writing, and every write that needs
//! one fails with EACCES until someone chowns them back by hand.
//!
//! So while a root process creates an entry, its *filesystem* identity is that
//! of the directory's owner (Linux `setfsuid`/`setfsgid`: per thread, and the
//! real and effective ids stay root). The kernel then creates the entry owned by
//! that user, and checks the access as that user. That is deliberately not a
//! chown afterwards: the directories involved are writable by the site's user,
//! so a root chown by path could be pointed, by swapping in a symlink between
//! the create and the chown, at any file on the system. A create made as the
//! site's user reaches nothing that user could not reach on its own.
//!
//! Only creation is wrapped. Opening, renaming and removing existing entries
//! keep the process's own rights, so a root CLI loses nothing it could do
//! before. Everything here is a no-op unless the process runs as root.
#![allow(dead_code)]

use std::os::unix::fs::MetadataExt;
use std::path::Path;

/// The filesystem identity of a directory's owner, held until dropped.
pub struct AsOwner {
    /// The fsuid/fsgid to restore; `None` when nothing was switched.
    prev: Option<(libc::uid_t, libc::gid_t)>,
}

/// Create entries as the owner of `dir` until the returned guard drops.
///
/// `dir` does not have to exist: the owner is that of its nearest existing
/// ancestor, the directory the first missing component will be created in, so
/// a `create_dir_all` under the guard hands the whole new chain to that owner.
pub fn of(dir: &Path) -> AsOwner {
    if unsafe { libc::geteuid() } != 0 {
        return AsOwner { prev: None };
    }
    let owner = dir
        .ancestors()
        .find_map(|p| std::fs::metadata(p).ok().filter(|m| m.is_dir()));
    match owner {
        Some(m) if (m.uid(), m.gid()) != (0, 0) => {
            let gid = unsafe { libc::setfsgid(m.gid()) } as libc::gid_t;
            let uid = unsafe { libc::setfsuid(m.uid()) } as libc::uid_t;
            AsOwner {
                prev: Some((uid, gid)),
            }
        }
        _ => AsOwner { prev: None },
    }
}

impl Drop for AsOwner {
    fn drop(&mut self) {
        if let Some((uid, gid)) = self.prev {
            // Back to root's fsuid restores the filesystem capabilities the
            // switch away from it cleared.
            unsafe {
                libc::setfsuid(uid);
                libc::setfsgid(gid);
            }
        }
    }
}
