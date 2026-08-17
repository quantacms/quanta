//! `qdbd` — the files-db daemon: single writer of the shared-memory data
//! segment that every PHP worker maps read-only.
//!
//! Boot: add inotify watches (watch-BEFORE-scan so nothing created mid-scan is
//! missed), walk the docroot reading every document into the in-RAM model,
//! project the model into a fresh segment, publish its epoch + the coherence
//! heartbeat. From then on the filesystem is read only for changes:
//!   * API writes arrive synchronously over the unix socket — the daemon
//!     re-reads the affected node from disk (files stay the source of truth),
//!     publishes to SHM, and only then acks (cross-process read-your-writes);
//!   * out-of-band changes (legacy writers, other replicas on a shared
//!     volume) arrive via inotify — dir create/delete/move events AND
//!     file-level `data*.json` writes — with a periodic reconcile as the
//!     safety net (also the only detection on volumes without inotify);
//!   * inotify queue overflow triggers a full resync; hitting the kernel
//!     watch limit degrades to reconcile-only.
//!
//! On exit the coherence flag is cleared and the extension falls back to its
//! walk-snapshot mode; a crash is covered by heartbeat staleness (<= 5 s).
//!
//! PHP-free: reuses the crate's modules via `#[path]` include (like `qdbstat`),
//! so it links neither the cdylib nor PHP.
#![allow(dead_code)]

#[path = "../error.rs"]
mod error;
#[path = "../paths.rs"]
mod paths;
#[path = "../metrics.rs"]
mod metrics;
#[path = "../shm.rs"]
mod shm;
#[path = "../config.rs"]
mod config;
#[path = "../store.rs"]
mod store;
#[path = "../php_abi.rs"]
mod php_abi;
#[path = "../image.rs"]
mod image;
#[path = "../model.rs"]
mod model;
#[path = "../ipc.rs"]
mod ipc;

use std::collections::{HashMap, HashSet};
use std::io;
use std::os::unix::io::AsRawFd;
use std::os::unix::net::{UnixListener, UnixStream};
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicBool, Ordering};
use std::time::{Duration, Instant};

use inotify::{EventMask, Inotify, WatchDescriptor, WatchMask};
use serde_json::{json, Value};

use config::Config;
use model::Model;
use shm::SegmentWriter;

const USAGE: &str = "\
qdbd — files-db daemon: serves the whole node tree from shared memory

USAGE:
    qdbd [OPTIONS]

OPTIONS:
    -r, --root <PATH>       Data root (default: $QUANTA_DB_ROOT).
        --resync-secs <N>   Full safety-net reconcile interval (default: 60).
    -1, --once              Load + publish one snapshot, print status, exit.
    -v, --verbose           Log every applied event to stderr.
    -h, --help              Show this help.

The segment it maintains is per-pod (tmpfs), so run one qdbd per app container
(the entrypoint does this when QUANTA_DB_DAEMON is not off).";

/// Directory events + file-level doc/symlink events. `ONLYDIR` only constrains
/// what may be WATCHED (directories); events inside still cover files, which
/// we need now that documents live in SHM (a `data.json` overwrite must
/// invalidate the segment, not wait for the reconcile).
fn watch_mask() -> WatchMask {
    WatchMask::CREATE
        | WatchMask::DELETE
        | WatchMask::MOVED_FROM
        | WatchMask::MOVED_TO
        | WatchMask::CLOSE_WRITE
        | WatchMask::DELETE_SELF
        | WatchMask::MOVE_SELF
        | WatchMask::ONLYDIR
}

/// How long an unmatched MOVED_FROM is held before it counts as a departure.
/// It only has to outlast the gap between the two events of one `rename()`
/// (microseconds), so this is generous; the cost of overshooting is that a node
/// deleted out of band keeps answering for that much longer, which the tree walk
/// behind `--resync-secs` already tolerates on a far coarser scale.
const MOVED_GRACE: Duration = Duration::from_millis(50);

static STOP: AtomicBool = AtomicBool::new(false);

extern "C" fn on_signal(_sig: libc::c_int) {
    STOP.store(true, Ordering::SeqCst);
}

struct Args {
    root: Option<PathBuf>,
    resync_secs: u64,
    once: bool,
    verbose: bool,
}

fn parse_args() -> Result<Args, String> {
    let mut a = Args {
        root: std::env::var_os("QUANTA_DB_ROOT").map(PathBuf::from),
        resync_secs: 60,
        once: false,
        verbose: false,
    };
    let mut it = std::env::args().skip(1);
    while let Some(arg) = it.next() {
        let mut next = |flag: &str| it.next().ok_or_else(|| format!("{flag} needs a value"));
        match arg.as_str() {
            "-r" | "--root" => a.root = Some(PathBuf::from(next("--root")?)),
            "--resync-secs" => {
                a.resync_secs = next("--resync-secs")?
                    .parse()
                    .map_err(|_| "invalid --resync-secs".to_string())?;
                if a.resync_secs == 0 {
                    return Err("--resync-secs must be > 0".into());
                }
            }
            "-1" | "--once" => a.once = true,
            "-v" | "--verbose" => a.verbose = true,
            "-h" | "--help" => {
                println!("{USAGE}");
                std::process::exit(0);
            }
            other => return Err(format!("unknown argument: {other}")),
        }
    }
    Ok(a)
}

/// One inotify event, copied to owned data so we can mutate the watch set while
/// processing (the borrow on the read buffer is released first).
struct Ev {
    wd: WatchDescriptor,
    mask: EventMask,
    /// Ties a MOVED_FROM to the MOVED_TO of the same `rename()`; 0 for the
    /// events that are not half of a rename.
    cookie: u32,
    name: Option<String>,
}

/// "data.json" -> Some(""), "data_it.json" -> Some("it"), else None.
fn doc_lang_of(file: &str) -> Option<String> {
    if file == "data.json" {
        return Some(String::new());
    }
    file.strip_prefix("data_")
        .and_then(|r| r.strip_suffix(".json"))
        .map(str::to_string)
}

struct Daemon {
    cfg: Config,
    model: Model,
    writer: SegmentWriter,
    doc_bytes: u64,
    img_bytes: u64,
    inotify: Inotify,
    watches: HashMap<WatchDescriptor, PathBuf>,
    /// MOVED_FROMs still waiting for their counterpart: (old path, rename
    /// cookie, when it was held) — see [`Daemon::settle_moved_away`].
    moved_pending: Vec<(PathBuf, u32, Instant)>,
    /// Cookies of the dir MOVED_TOs seen so far, so a rename whose two halves
    /// land in different reads still pairs up.
    moved_arrivals: HashMap<u32, Instant>,
    degraded: bool,
    verbose: bool,
}

impl Daemon {
    // -- watches ------------------------------------------------------------

    /// Add a watch on `dir` and recurse into its (non-skipped) subdirectories.
    /// Sets `degraded` and stops adding on `ENOSPC` (kernel watch limit).
    fn add_watches(&mut self, dir: &Path) {
        if self.degraded {
            return;
        }
        match self.inotify.watches().add(dir, watch_mask()) {
            Ok(wd) => {
                self.watches.insert(wd, dir.to_path_buf());
            }
            Err(e) if e.raw_os_error() == Some(libc::ENOSPC) => {
                eprintln!(
                    "qdbd: kernel inotify watch limit hit (max_user_watches); \
                     degrading to periodic resync only. Raise fs.inotify.max_user_watches."
                );
                self.degraded = true;
                return;
            }
            Err(_) => return, // dir vanished mid-scan, or not a dir — ignore
        }
        let Ok(rd) = std::fs::read_dir(dir) else { return };
        for e in rd.flatten() {
            let Ok(ft) = e.file_type() else { continue };
            if !ft.is_dir() {
                continue; // is_dir() is false for symlinks — mirror walk/skip
            }
            let path = e.path();
            let name = e.file_name().to_string_lossy().to_string();
            if store::skip_dir(&self.cfg, &path, &name) {
                continue;
            }
            self.add_watches(&path);
            if self.degraded {
                return;
            }
        }
    }

    // -- segment publication -------------------------------------------------

    /// Serialize the whole model into a brand-new segment (next epoch) and
    /// flip the published epoch. Old segments beyond the immediate predecessor
    /// are unlinked (a reader that raced the flip can still map epoch-1).
    fn publish_full(&mut self) -> io::Result<()> {
        let epoch = self.writer.epoch + 1;
        let encoded: Vec<(String, Vec<u8>)> = self
            .model
            .nodes
            .iter()
            .map(|(n, m)| (n.clone(), m.encode()))
            .collect();
        let live: u64 = encoded.iter().map(|(_, b)| b.len() as u64).sum();
        let slots = shm::slot_count_for(self.model.nodes.len() as u64);
        let size = shm::seg_size_for(live, slots, self.cfg.shm_size_mb << 20);
        let mut w = SegmentWriter::create(&self.cfg.shm_dir, epoch, size, slots, self.cfg.root_hash)?;
        for (name, bytes) in &encoded {
            w.upsert(name, bytes)
                .map_err(|e| io::Error::other(format!("fresh segment overflow: {e:?}")))?;
        }
        w.set_counts(
            self.model.nodes.len() as u64,
            self.model.links.len() as u64,
            self.doc_bytes,
            self.img_bytes,
        );
        w.publish_ready();
        self.writer = w;
        metrics::set_data_epoch(epoch);
        self.cleanup_segments(epoch);
        if self.verbose {
            eprintln!("qdbd: published epoch {epoch} ({} nodes)", self.model.nodes.len());
        }
        Ok(())
    }

    /// Remove segment files older than the predecessor of `current`.
    fn cleanup_segments(&self, current: u64) {
        let Ok(rd) = std::fs::read_dir(&self.cfg.shm_dir) else { return };
        for e in rd.flatten() {
            let name = e.file_name().to_string_lossy().to_string();
            if let Some(num) = name
                .strip_prefix("data.")
                .and_then(|r| r.strip_suffix(".shm"))
                .and_then(|n| n.parse::<u64>().ok())
            {
                if num + 1 < current {
                    let _ = std::fs::remove_file(e.path());
                }
            }
        }
    }

    /// (Re)publish one node's record; a full segment triggers compaction
    /// (which already contains the node, so no retry is needed).
    fn publish_node(&mut self, name: &str) {
        let Some(node) = self.model.nodes.get(name) else { return };
        let bytes = node.encode();
        if self.writer.upsert(name, &bytes).is_err() {
            if let Err(e) = self.publish_full() {
                eprintln!("qdbd: compaction failed: {e}");
            }
            return;
        }
        self.refresh_counts();
    }

    fn tombstone_node(&mut self, name: &str) {
        let gen = metrics::next_generation();
        if self.writer.tombstone(name, gen).is_err() {
            if let Err(e) = self.publish_full() {
                eprintln!("qdbd: compaction failed: {e}");
            }
            return;
        }
        self.refresh_counts();
    }

    fn refresh_counts(&self) {
        self.writer.set_counts(
            self.model.nodes.len() as u64,
            self.model.links.len() as u64,
            self.doc_bytes,
            self.img_bytes,
        );
    }

    fn maybe_compact(&mut self) {
        if self.writer.should_compact() {
            if let Err(e) = self.publish_full() {
                eprintln!("qdbd: compaction failed: {e}");
            }
        }
    }

    // -- model mutations ------------------------------------------------------

    /// Reload `name`'s children list from disk; republish when it changed.
    /// No generation bump: child lists are not document content.
    fn refresh_children(&mut self, name: &str) {
        let Some(node) = self.model.nodes.get(name) else { return };
        let fresh = store::list_children(&node.abs_path(&self.cfg));
        if fresh != node.children {
            self.model.nodes.get_mut(name).expect("checked").children = fresh;
            self.publish_node(name);
        }
    }

    fn refresh_father_of(&mut self, father: Option<String>) {
        if let Some(f) = father {
            self.refresh_children(&f);
        }
    }

    /// (Re)load one node from disk into the model + segment. The single write
    /// path for both UDS upserts and inotify-discovered changes.
    fn apply_upsert(&mut self, name: &str, path: &Path) -> Result<u64, String> {
        if !path.is_dir() {
            return Err(format!("{} is not a directory", path.display()));
        }
        let father = store::father_of(&self.cfg, path);
        let mut node = model::load_node(&self.cfg, name, path, father.clone());
        node.generation = metrics::next_generation();
        let gen = node.generation;
        if let Some(old) = self.model.nodes.get(name) {
            self.doc_bytes = self.doc_bytes.saturating_sub(old.doc_bytes());
            self.img_bytes = self.img_bytes.saturating_sub(old.img_bytes());
            node.inlinks = old.inlinks.clone();
        }
        self.doc_bytes += node.doc_bytes();
        self.img_bytes += node.img_bytes();
        self.model.nodes.insert(name.to_string(), node);
        self.publish_node(name);
        self.refresh_father_of(father);
        // A brand-new dir needs a watch for its future doc events; an API
        // create may beat the inotify CREATE event that would have added it.
        self.add_watches(path);
        self.maybe_compact();
        Ok(gen)
    }

    /// Drop `name` and its whole subtree (its dir is gone/trashed) plus every
    /// link edge touching the removed names.
    fn apply_delete(&mut self, name: &str) {
        let Some(node) = self.model.nodes.get(name) else { return };
        let base_rel = node.rel_path.clone();
        let father = node.father.clone();
        self.prune_rel(&base_rel);
        self.refresh_father_of(father);
    }

    /// Drop every row still filed under the `base_rel` path prefix, plus the
    /// link edges touching them and the watches beneath it.
    ///
    /// Keyed on a path prefix rather than on a name because `move` needs it that
    /// way: once the subtree has been republished at its new location, what has
    /// to go is whatever is *still* filed under the old path — which for a plain
    /// move is nothing at all, since every row was overwritten in place.
    fn prune_rel(&mut self, base_rel: &str) {
        let prefix = format!("{base_rel}/");
        let victims: Vec<String> = self
            .model
            .nodes
            .iter()
            .filter(|(_, m)| m.rel_path == base_rel || m.rel_path.starts_with(&prefix))
            .map(|(n, _)| n.clone())
            .collect();
        if victims.is_empty() {
            return;
        }
        let victim_set: HashSet<&String> = victims.iter().collect();
        // Counterparts of removed edges need republishing (inlinks/children).
        let mut affected: HashSet<String> = HashSet::new();
        self.model.links.retain(|(c, t)| {
            let dead = victim_set.contains(c) || victim_set.contains(t);
            if dead {
                if !victim_set.contains(c) {
                    affected.insert(c.clone());
                }
                if !victim_set.contains(t) {
                    affected.insert(t.clone());
                }
            }
            !dead
        });
        for v in &victims {
            if let Some(m) = self.model.nodes.remove(v) {
                self.doc_bytes = self.doc_bytes.saturating_sub(m.doc_bytes());
                self.img_bytes = self.img_bytes.saturating_sub(m.img_bytes());
            }
        }
        self.model.rebuild_inlinks();
        for v in &victims {
            self.tombstone_node(v);
        }
        for a in affected {
            if self.model.nodes.contains_key(&a) {
                self.refresh_children(&a);
                self.publish_node(&a); // inlinks may have changed even if children didn't
            }
        }
        self.watches.retain(|_, p| {
            p.strip_prefix(&self.cfg.root)
                .map(|rel| {
                    let rel = rel.to_string_lossy();
                    !(rel == base_rel || rel.starts_with(&prefix))
                })
                .unwrap_or(true)
        });
        self.maybe_compact();
    }

    /// Set or clear one (container, target) edge; refresh both records.
    fn apply_link(&mut self, container: &str, target: &str, present: bool) {
        let edge = (container.to_string(), target.to_string());
        if present {
            self.model.links.insert(edge);
            if let Some(t) = self.model.nodes.get_mut(target) {
                t.inlinks.insert(container.to_string());
            }
        } else {
            self.model.links.remove(&edge);
            if let Some(t) = self.model.nodes.get_mut(target) {
                t.inlinks.remove(container);
            }
        }
        self.refresh_children(container);
        if self.model.nodes.contains_key(container) {
            self.publish_node(container);
        }
        if self.model.nodes.contains_key(target) {
            self.publish_node(target);
        }
        self.maybe_compact();
    }

    /// Re-derive a container's outgoing link edges from its dir contents —
    /// covers out-of-band symlink create/delete/rename events.
    fn rescan_membership(&mut self, dir: &Path) {
        if dir == self.cfg.root {
            return; // root is not a node/container
        }
        let Some(container) = dir.file_name().map(|s| s.to_string_lossy().to_string()) else {
            return;
        };
        if !self.model.nodes.contains_key(&container) {
            return;
        }
        // Current on-disk edges from this container.
        let mut fresh: HashSet<(String, String)> = HashSet::new();
        if let Ok(rd) = std::fs::read_dir(dir) {
            for e in rd.flatten() {
                let Ok(ft) = e.file_type() else { continue };
                if !ft.is_symlink() {
                    continue;
                }
                if let Ok(t) = std::fs::canonicalize(e.path()) {
                    if t.is_dir() && t.starts_with(&self.cfg.root) {
                        if let Some(tn) = t.file_name().map(|s| s.to_string_lossy().to_string()) {
                            fresh.insert((container.clone(), tn));
                        }
                    }
                }
            }
        }
        let mut touched: HashSet<String> = HashSet::new();
        self.model.links.retain(|(c, t)| {
            if c == &container && !fresh.contains(&(c.clone(), t.clone())) {
                touched.insert(t.clone());
                return false;
            }
            true
        });
        for edge in fresh {
            touched.insert(edge.1.clone());
            self.model.links.insert(edge);
        }
        self.model.rebuild_inlinks();
        self.refresh_children(&container);
        self.publish_node(&container);
        for t in touched {
            if self.model.nodes.contains_key(&t) {
                self.publish_node(&t);
            }
        }
        self.maybe_compact();
    }

    // -- inotify handlers -----------------------------------------------------

    /// A directory appeared: load its whole subtree into model + segment.
    fn on_dir_added(&mut self, path: &Path) {
        // Watch BEFORE reading. inotify queues events from the moment a watch
        // exists, so anything written while we walk is delivered afterwards
        // instead of being lost. Reading first left a window in which a node
        // created externally (mkdir + file_put_contents, i.e. open(O_TRUNC) then
        // write) could be read at zero bytes, latched as LANG_CORRUPT, and then
        // never re-read because its CLOSE_WRITE landed before the watch existed
        // — leaving a valid node throwing CORRUPT_JSON on every read until the
        // next reconcile (--resync-secs, default 60). A redundant event from
        // watching early is free: on_doc_event's stat check drops no-ops.
        self.add_watches(path);
        let mut nodes = Vec::new();
        let mut links = Vec::new();
        store::walk(&self.cfg, path, &mut nodes, &mut links);
        for wn in nodes {
            if let Err(e) = self.apply_upsert(&wn.name, &wn.path) {
                if self.verbose {
                    eprintln!("qdbd: upsert {} failed: {e}", wn.name);
                }
            }
        }
        for (c, t) in links {
            self.apply_link(&c, &t, true);
        }
    }

    /// A directory vanished (delete or moved away).
    /// Settle the MOVED_FROM events of one inotify batch.
    ///
    /// A MOVED_FROM says the directory went *somewhere*; DELETE says it is gone.
    /// Treating them alike turned every in-tree rename into a delete followed by
    /// an add, and a lookup landing between the two got an **authoritative**
    /// "no such node" — for a node that existed the whole time, which callers
    /// act on (`Environment::nodePath` skips its legacy `find` on exactly that
    /// answer). Deferring to the end of the batch covers the common case: the
    /// matching MOVED_TO comes from the same `rename()` and usually lands in the
    /// same read, so by then it has already re-pointed the model.
    ///
    /// It does not cover all of them. The kernel queues the two events one after
    /// the other, not as a pair, so a reader can drain the queue in between and
    /// get a batch holding only the MOVED_FROM.
    ///
    /// What settles such a leftover is the rename **cookie**, not the model. The
    /// tempting test — the model still files the node here and the path is gone
    /// — proves only that the model is *stale about this node*, never that the
    /// node left the tree, and the two are indistinguishable from a snapshot: a
    /// node being moved every few hundred microseconds is permanently "stale"
    /// between the `rename()` and the writer's UDS `move`. Asking that question
    /// later just lands the delete on a *different* in-flight rename. The cookie
    /// ties the departure to one specific `rename()`: if that rename's MOVED_TO
    /// landed anywhere in the watched tree, the node is still here and
    /// [`Self::on_dir_added`] has already filed it at its new path. Only a
    /// rename whose other half never arrives took the node out of the tree.
    fn settle_moved_away(&mut self, moved: &[(PathBuf, u32)]) {
        for (path, cookie) in moved {
            if self.moved_arrivals.remove(cookie).is_none() {
                self.moved_pending.push((path.clone(), *cookie, Instant::now()));
            }
            // A re-watched directory carries its new path under the same
            // descriptor, so this only drops entries that really are stale.
            self.watches.retain(|_, p| !p.starts_with(path));
        }
    }

    /// Does the model still file a node at `path`, with nothing on disk there?
    /// Necessary for a departure, never sufficient — see [`Self::settle_moved_away`].
    fn moved_away_for_good(&self, path: &Path) -> bool {
        let Some(name) = path.file_name().map(|s| s.to_string_lossy().to_string()) else {
            return false;
        };
        self.model
            .nodes
            .get(&name)
            .map(|m| m.abs_path(&self.cfg) == *path)
            .unwrap_or(false)
            && !path.exists()
    }

    /// Settle the held MOVED_FROMs whose grace has run out. The counterpart is
    /// queued by the same `rename()` microseconds later, so anything still
    /// unpaired after a grace that spans several reads has no counterpart in
    /// this tree: the directory was renamed out of it (or into a skipped
    /// subtree), which is a departure.
    fn settle_pending_moves(&mut self) {
        self.moved_arrivals.retain(|_, at| at.elapsed() < MOVED_GRACE * 2);
        if self.moved_pending.is_empty() {
            return;
        }
        let mut due: Vec<(PathBuf, u32)> = Vec::new();
        self.moved_pending.retain(|(path, cookie, since)| {
            if since.elapsed() < MOVED_GRACE {
                return true;
            }
            due.push((path.clone(), *cookie));
            false
        });
        for (path, cookie) in due {
            if self.moved_arrivals.remove(&cookie).is_some() {
                continue; // the other half arrived in a later read
            }
            if !self.moved_away_for_good(&path) {
                continue;
            }
            if let Some(name) = path.file_name().map(|s| s.to_string_lossy().to_string()) {
                if self.verbose {
                    eprintln!("qdbd: - {} (moved out of the tree)", path.display());
                }
                self.apply_delete(&name);
            }
        }
    }

    fn on_dir_removed(&mut self, path: &Path) {
        if let Some(name) = path.file_name().map(|s| s.to_string_lossy().to_string()) {
            // Only meaningful if the model's node for this NAME lived at this
            // PATH (a same-named node elsewhere must not be clobbered).
            let matches = self
                .model
                .nodes
                .get(&name)
                .map(|m| m.abs_path(&self.cfg) == path)
                .unwrap_or(false);
            if matches {
                self.apply_delete(&name);
            }
        }
        self.watches.retain(|_, p| !p.starts_with(path));
    }

    /// A `data*.json` inside `dir` changed/appeared/vanished.
    fn on_doc_event(&mut self, dir: &Path, lang: &str) {
        if dir == self.cfg.root {
            return;
        }
        let Some(name) = dir.file_name().map(|s| s.to_string_lossy().to_string()) else {
            return;
        };
        let Some(node) = self.model.nodes.get(&name) else {
            return; // unknown dir (skip-dir or not yet added); reconcile covers
        };
        if node.abs_path(&self.cfg) != dir {
            return; // same-named node elsewhere
        }
        if store::in_payload_subtree(&node.rel_path) {
            return; // payload subtree: documents are never loaded (see model::load_node)
        }
        // Skip no-ops (our own UDS-acked write fires an event too): the model
        // already carries this exact file state.
        let on_disk = store::stat_doc(dir, lang);
        let in_model = node.docs.iter().find(|d| d.lang == lang);
        let unchanged = match (&on_disk, in_model) {
            (Some(st), Some(d)) => st.mtime == d.mtime && st.size == d.size,
            (None, None) => true,
            _ => false,
        };
        if unchanged {
            return;
        }
        let path = dir.to_path_buf();
        if let Err(e) = self.apply_upsert(&name, &path) {
            if self.verbose {
                eprintln!("qdbd: doc reload {name} failed: {e}");
            }
        }
    }

    // -- reconcile --------------------------------------------------------------

    /// Presence + drift sync of the whole tree against the model. Documents
    /// are only re-read when their stat changed, so an idle reconcile costs
    /// one walk + stats, and unchanged nodes keep their generations (live
    /// parse caches stay valid).
    fn reconcile(&mut self) {
        let (wnodes, wlinks) = model::walk_dedup(&self.cfg, &self.cfg.root);
        let mut changed: HashSet<String> = HashSet::new();

        let disk: HashMap<String, &store::WalkNode> =
            wnodes.iter().map(|n| (n.name.clone(), n)).collect();

        // Vanished nodes.
        let gone: Vec<String> = self
            .model
            .nodes
            .keys()
            .filter(|n| !disk.contains_key(*n))
            .cloned()
            .collect();
        for name in gone {
            if let Some(m) = self.model.nodes.remove(&name) {
                self.doc_bytes = self.doc_bytes.saturating_sub(m.doc_bytes());
                self.img_bytes = self.img_bytes.saturating_sub(m.img_bytes());
            }
            self.tombstone_node(&name);
        }

        // Link edges wholesale; counterparts of any diff get republished.
        let fresh_links: HashSet<(String, String)> = wlinks.into_iter().collect();
        if fresh_links != self.model.links {
            for (c, t) in self.model.links.symmetric_difference(&fresh_links) {
                changed.insert(c.clone());
                changed.insert(t.clone());
            }
            self.model.links = fresh_links;
        }

        // New / moved / drifted nodes.
        for wn in &wnodes {
            let rel = wn
                .path
                .strip_prefix(&self.cfg.root)
                .map(|p| p.to_string_lossy().to_string())
                .unwrap_or_default();
            let drift = match self.model.nodes.get(&wn.name) {
                None => true,
                Some(node) => {
                    node.rel_path != rel
                        || docs_drift(&wn.path, node)
                        || node.children != store::list_children(&wn.path)
                }
            };
            if drift {
                let mut node =
                    model::load_node(&self.cfg, &wn.name, &wn.path, wn.father.clone());
                node.generation = metrics::next_generation();
                if let Some(old) = self.model.nodes.get(&wn.name) {
                    self.doc_bytes = self.doc_bytes.saturating_sub(old.doc_bytes());
                    self.img_bytes = self.img_bytes.saturating_sub(old.img_bytes());
                }
                self.doc_bytes += node.doc_bytes();
                self.img_bytes += node.img_bytes();
                self.model.nodes.insert(wn.name.clone(), node);
                changed.insert(wn.name.clone());
            }
        }

        self.model.rebuild_inlinks();
        let names: Vec<String> = changed
            .into_iter()
            .filter(|n| self.model.nodes.contains_key(n))
            .collect();
        for name in names {
            self.publish_node(&name);
        }
        self.refresh_counts();

        // Refresh the watch set: prune vanished dirs, pick up new ones.
        self.watches.retain(|_, p| p.exists());
        if !self.degraded {
            let root = self.cfg.root.clone();
            self.add_watches(&root);
        }
        self.maybe_compact();
        metrics::bump_epoch();
        metrics::record_resync();
    }

    /// Full reload from disk into a fresh segment (reindex / queue overflow).
    fn full_rebuild(&mut self) -> Result<(usize, usize), String> {
        self.model = model::build_from_disk(&self.cfg, metrics::next_generation);
        self.doc_bytes = self.model.doc_bytes();
        self.img_bytes = self.model.img_bytes();
        self.publish_full().map_err(|e| e.to_string())?;
        self.watches.retain(|_, p| p.exists());
        if !self.degraded {
            let root = self.cfg.root.clone();
            self.add_watches(&root);
        }
        metrics::bump_epoch();
        metrics::record_resync();
        Ok((self.model.nodes.len(), self.model.links.len()))
    }

    /// Reindex one subtree: re-walk it, merge, drop what vanished under it.
    fn reindex_subtree(&mut self, name: &str) -> Result<(usize, usize), String> {
        let base = match self.model.nodes.get(name) {
            Some(n) => n.abs_path(&self.cfg),
            None => store::fs_search(&self.cfg, name)
                .ok_or_else(|| format!("subtree node '{name}' not found"))?,
        };
        self.reindex_at(&base)
    }

    /// The body of a subtree reindex against an explicit directory. `move` needs
    /// this form: the node it has to re-read sits at a path the model does not
    /// know yet, so there is nothing to resolve the base from.
    fn reindex_at(&mut self, base: &Path) -> Result<(usize, usize), String> {
        let (wnodes, wlinks) = model::walk_dedup(&self.cfg, base);
        let counts = (wnodes.len(), wlinks.len());
        let walked: HashSet<String> = wnodes.iter().map(|n| n.name.clone()).collect();

        // Drop model nodes under the base that the walk no longer found.
        let base_rel = base
            .strip_prefix(&self.cfg.root)
            .map(|p| p.to_string_lossy().to_string())
            .unwrap_or_default();
        let prefix = format!("{base_rel}/");
        let gone: Vec<String> = self
            .model
            .nodes
            .iter()
            .filter(|(n, m)| {
                (m.rel_path == base_rel || m.rel_path.starts_with(&prefix))
                    && !walked.contains(*n)
            })
            .map(|(n, _)| n.clone())
            .collect();
        for g in gone {
            if let Some(m) = self.model.nodes.remove(&g) {
                self.doc_bytes = self.doc_bytes.saturating_sub(m.doc_bytes());
                self.img_bytes = self.img_bytes.saturating_sub(m.img_bytes());
            }
            self.tombstone_node(&g);
        }
        for wn in &wnodes {
            if let Err(e) = self.apply_upsert(&wn.name, &wn.path) {
                return Err(e);
            }
        }
        for (c, t) in wlinks {
            self.apply_link(&c, &t, true);
        }
        Ok(counts)
    }

    // -- UDS request handling ----------------------------------------------------

    fn handle_request(&mut self, req: &Value) -> Value {
        let op = req.get("op").and_then(Value::as_str).unwrap_or("");
        let get = |k: &str| req.get(k).and_then(Value::as_str).unwrap_or("").to_string();
        let epoch = self.writer.epoch;
        match op {
            "ping" => json!({
                "ok": true,
                "epoch": epoch,
                "nodes": self.model.nodes.len(),
                "pid": std::process::id(),
            }),
            "upsert" => {
                let name = get("name");
                let path = self.cfg.root.join(get("rel_path"));
                match self.apply_upsert(&name, &path) {
                    Ok(generation) => {
                        json!({"ok": true, "generation": generation, "epoch": self.writer.epoch})
                    }
                    Err(e) => json!({"ok": false, "error": e}),
                }
            }
            "delete" => {
                self.apply_delete(&get("name"));
                json!({"ok": true, "epoch": self.writer.epoch})
            }
            "link" => {
                self.apply_link(&get("container"), &get("target"), true);
                json!({"ok": true, "epoch": self.writer.epoch})
            }
            "unlink" => {
                self.apply_link(&get("container"), &get("target"), false);
                json!({"ok": true, "epoch": self.writer.epoch})
            }
            "relink" => {
                let target = get("target");
                self.apply_link(&get("from"), &target, false);
                self.apply_link(&get("to"), &target, true);
                json!({"ok": true, "epoch": self.writer.epoch})
            }
            "move" => {
                let to = self.cfg.root.join(get("to_rel"));
                // Both ends come from the message, never from the model: the
                // directory rename also fires inotify MOVED_FROM/MOVED_TO, so
                // the model may already have been re-pointed at the new
                // location by the time this arrives. Reading the "old" path
                // from it would then name the *new* one — and pruning that
                // would delete the node this very request just published.
                let old_rel = get("from_rel");
                let old_father = store::father_of(&self.cfg, &self.cfg.root.join(&old_rel));
                // Publish the new location FIRST, then clear the old one. A
                // plain move keeps every name, so each slot flips straight from
                // the old record to the new one and a reader probing mid-move
                // sees one or the other. Deleting first would tombstone the
                // whole subtree for the length of the re-walk, and a lookup
                // landing in that window gets an *authoritative* absence — the
                // caller would take it as proof the node is gone.
                match self.reindex_at(&to) {
                    Ok(_) => {
                        // Whatever is still filed under the old path did not
                        // travel: it was deleted, or (on a rename) is the old
                        // name, whose row nothing overwrote. For a plain move
                        // this matches nothing, because every row was rewritten
                        // in place above. (reindex_at has already pruned stale
                        // rows under the destination, which covers content
                        // `if_exists=replace` displaced.)
                        if !old_rel.is_empty() {
                            self.prune_rel(&old_rel);
                        }
                        // An inbound edge lives in the container's directory, so
                        // only a rescan there re-derives it — needed because the
                        // extension re-pointed (and possibly renamed) each link
                        // on disk, and because prune_rel may have dropped edges.
                        let dirs: Vec<PathBuf> = req
                            .get("containers")
                            .and_then(Value::as_array)
                            .map(|a| {
                                a.iter()
                                    .filter_map(Value::as_str)
                                    .filter_map(|c| {
                                        self.model.nodes.get(c).map(|n| n.abs_path(&self.cfg))
                                    })
                                    .collect()
                            })
                            .unwrap_or_default();
                        for dir in dirs {
                            self.rescan_membership(&dir);
                        }
                        self.refresh_father_of(old_father);
                        json!({"ok": true, "epoch": self.writer.epoch})
                    }
                    Err(e) => json!({"ok": false, "error": e}),
                }
            }
            "reindex" => {
                let started = Instant::now();
                let r = match req.get("subtree").and_then(Value::as_str) {
                    Some(s) => self.reindex_subtree(s),
                    None => self.full_rebuild(),
                };
                match r {
                    Ok((nodes, links)) => json!({
                        "ok": true,
                        "nodes": nodes,
                        "links": links,
                        "seconds": started.elapsed().as_secs_f64(),
                        "epoch": self.writer.epoch,
                    }),
                    Err(e) => json!({"ok": false, "error": e}),
                }
            }
            other => json!({"ok": false, "error": format!("unknown op '{other}'")}),
        }
    }
}

/// Any doc file under `path` whose stat differs from the model (or a language
/// appearing/vanishing) counts as drift.
fn docs_drift(path: &Path, node: &model::NodeModel) -> bool {
    let langs = store::langs_of(path);
    if langs.len() != node.docs.len() {
        return true;
    }
    for lang in langs {
        let Some(d) = node.docs.iter().find(|d| d.lang == lang) else {
            return true;
        };
        match store::stat_doc(path, &lang) {
            Some(st) => {
                if st.mtime != d.mtime || st.size != d.size {
                    return true;
                }
            }
            None => return true,
        }
    }
    false
}

fn main() {
    let args = match parse_args() {
        Ok(a) => a,
        Err(e) => {
            eprintln!("qdbd: {e}\n\n{USAGE}");
            std::process::exit(2);
        }
    };
    if let Some(root) = &args.root {
        std::env::set_var("QUANTA_DB_ROOT", root);
    }

    let cfg = match config::build_with(|_name| None) {
        Ok(c) => c,
        Err(e) => {
            eprintln!("qdbd: {e}");
            std::process::exit(1);
        }
    };

    // The arena is the daemon/extension control plane (heartbeat, epoch,
    // generation counter) — always mapped writable here.
    metrics::init(&cfg.metrics_path, true);
    metrics::set_daemon_pid(std::process::id() as u64);

    unsafe {
        libc::signal(libc::SIGTERM, on_signal as *const () as usize);
        libc::signal(libc::SIGINT, on_signal as *const () as usize);
        libc::signal(libc::SIGPIPE, libc::SIG_IGN); // client died mid-ack
    }

    let inotify = match Inotify::init() {
        Ok(i) => i,
        Err(e) => {
            eprintln!("qdbd: inotify init failed: {e}");
            std::process::exit(1);
        }
    };
    let ifd = inotify.as_raw_fd();
    unsafe {
        let flags = libc::fcntl(ifd, libc::F_GETFL);
        libc::fcntl(ifd, libc::F_SETFL, flags | libc::O_NONBLOCK);
    }

    // Stale segments from a previous daemon are unreadable garbage to current
    // readers (the heartbeat is stale, so nobody trusts them) — start clean.
    if let Ok(rd) = std::fs::read_dir(&cfg.shm_dir) {
        for e in rd.flatten() {
            let name = e.file_name().to_string_lossy().to_string();
            if name.starts_with("data.") && name.ends_with(".shm") {
                let _ = std::fs::remove_file(e.path());
            }
        }
    }

    let boot_epoch = metrics::data_epoch() + 1;
    let placeholder = match SegmentWriter::create(&cfg.shm_dir, boot_epoch, 0, 8192, cfg.root_hash)
    {
        Ok(w) => w,
        Err(e) => {
            eprintln!(
                "qdbd: cannot create segment in {} ({e}); set QUANTA_DB_SHM_DIR",
                cfg.shm_dir.display()
            );
            std::process::exit(1);
        }
    };

    let mut d = Daemon {
        cfg,
        model: Model::default(),
        writer: placeholder,
        doc_bytes: 0,
        img_bytes: 0,
        inotify,
        watches: HashMap::new(),
        moved_pending: Vec::new(),
        moved_arrivals: HashMap::new(),
        degraded: false,
        verbose: args.verbose,
    };

    // Watch BEFORE the snapshot so anything created during the scan still
    // fires an event (idempotent upserts absorb the overlap).
    let root = d.cfg.root.clone();
    d.add_watches(&root);
    let started = Instant::now();
    d.model = model::build_from_disk(&d.cfg, metrics::next_generation);
    d.doc_bytes = d.model.doc_bytes();
    d.img_bytes = d.model.img_bytes();
    if let Err(e) = d.publish_full() {
        eprintln!("qdbd: cannot publish initial segment: {e}");
        std::process::exit(1);
    }

    // Socket AFTER the initial publish: a connect implies the segment serves.
    let _ = std::fs::remove_file(&d.cfg.socket_path);
    if let Some(dir) = d.cfg.socket_path.parent() {
        let _ = std::fs::create_dir_all(dir);
    }
    let listener = match UnixListener::bind(&d.cfg.socket_path) {
        Ok(l) => l,
        Err(e) => {
            eprintln!("qdbd: cannot bind {}: {e}", d.cfg.socket_path.display());
            std::process::exit(1);
        }
    };
    let _ = listener.set_nonblocking(true);
    let lfd = listener.as_raw_fd();

    metrics::set_heartbeat();
    metrics::bump_epoch();
    metrics::set_coherent(true);

    println!(
        "qdbd: serving {} from shm ({} nodes, {} doc bytes, {} image bytes, \
         {} dir watches, epoch {}, {:.2}s{})",
        d.cfg.root.display(),
        d.model.nodes.len(),
        d.doc_bytes,
        d.img_bytes,
        d.watches.len(),
        d.writer.epoch,
        started.elapsed().as_secs_f64(),
        if d.degraded { ", DEGRADED: resync-only" } else { "" }
    );

    if args.once {
        metrics::set_coherent(false);
        let _ = std::fs::remove_file(&d.cfg.socket_path);
        return;
    }

    let resync = Duration::from_secs(args.resync_secs);
    let mut last_resync = Instant::now();
    let mut buffer = [0u8; 8192];
    let mut clients: Vec<UnixStream> = Vec::new();

    while !STOP.load(Ordering::SeqCst) {
        // Wait up to 1s for inotify or socket traffic; the timeout also paces
        // the heartbeat + periodic reconcile. A held MOVED_FROM shortens it to
        // its grace, so a node that really did leave the tree is not kept alive
        // by an otherwise idle loop.
        let mut pfds: Vec<libc::pollfd> = Vec::with_capacity(2 + clients.len());
        pfds.push(libc::pollfd { fd: ifd, events: libc::POLLIN, revents: 0 });
        pfds.push(libc::pollfd { fd: lfd, events: libc::POLLIN, revents: 0 });
        for c in &clients {
            pfds.push(libc::pollfd { fd: c.as_raw_fd(), events: libc::POLLIN, revents: 0 });
        }
        let wait_ms = if d.moved_pending.is_empty() {
            1000
        } else {
            MOVED_GRACE.as_millis() as libc::c_int
        };
        let r = unsafe { libc::poll(pfds.as_mut_ptr(), pfds.len() as libc::nfds_t, wait_ms) };

        if r > 0 {
            // Inotify events.
            if pfds[0].revents & libc::POLLIN != 0 {
                let mut moved_away: Vec<(PathBuf, u32)> = Vec::new();
                let evs: Vec<Ev> = match d.inotify.read_events(&mut buffer) {
                    Ok(events) => events
                        .map(|e| Ev {
                            wd: e.wd.clone(),
                            mask: e.mask,
                            cookie: e.cookie,
                            name: e.name.map(|n| n.to_string_lossy().into_owned()),
                        })
                        .collect(),
                    Err(_) => Vec::new(), // WouldBlock or transient — skip
                };
                for ev in evs {
                    if ev.mask.contains(EventMask::Q_OVERFLOW) {
                        if d.verbose {
                            eprintln!("qdbd: inotify queue overflow — full resync");
                        }
                        d.reconcile();
                        last_resync = Instant::now();
                        continue;
                    }
                    if ev.mask.contains(EventMask::IGNORED) {
                        d.watches.remove(&ev.wd); // watch auto-removed (dir gone)
                        continue;
                    }
                    let Some(dir) = d.watches.get(&ev.wd).cloned() else { continue };
                    let Some(name) = ev.name else { continue };
                    if ev.mask.contains(EventMask::ISDIR) {
                        let path = dir.join(&name);
                        if store::skip_dir(&d.cfg, &path, &name) {
                            continue;
                        }
                        if ev.mask.intersects(EventMask::CREATE | EventMask::MOVED_TO) {
                            if ev.cookie != 0 {
                                // An arrival inside the tree: whatever left with
                                // this cookie only changed place. Recorded even
                                // when its MOVED_FROM is still to come, since a
                                // read can split the pair either way.
                                d.moved_arrivals.insert(ev.cookie, Instant::now());
                            }
                            d.on_dir_added(&path);
                            metrics::record_watch_event();
                            if d.verbose {
                                eprintln!("qdbd: + {}", path.display());
                            }
                        } else if ev.mask.contains(EventMask::MOVED_FROM) {
                            // Went somewhere — settled after the batch, once
                            // the matching MOVED_TO has had its say.
                            moved_away.push((path.clone(), ev.cookie));
                            metrics::record_watch_event();
                            if d.verbose {
                                eprintln!("qdbd: > {}", path.display());
                            }
                        } else if ev.mask.contains(EventMask::DELETE) {
                            d.on_dir_removed(&path);
                            metrics::record_watch_event();
                            if d.verbose {
                                eprintln!("qdbd: - {}", path.display());
                            }
                        }
                    } else if let Some(lang) = doc_lang_of(&name) {
                        // data*.json content events: overwrite (CLOSE_WRITE),
                        // atomic-rename replace (MOVED_TO), delete.
                        if ev.mask.intersects(
                            EventMask::CLOSE_WRITE
                                | EventMask::MOVED_TO
                                | EventMask::DELETE
                                | EventMask::MOVED_FROM,
                        ) {
                            d.on_doc_event(&dir, &lang);
                            metrics::record_watch_event();
                            if d.verbose {
                                eprintln!("qdbd: ~ {}/{}", dir.display(), name);
                            }
                        }
                    } else if ev.mask.intersects(
                        EventMask::CREATE
                            | EventMask::DELETE
                            | EventMask::MOVED_TO
                            | EventMask::MOVED_FROM,
                    ) && !name.starts_with('.')
                    {
                        // Probably a symlink (container membership) change.
                        d.rescan_membership(&dir);
                        metrics::record_watch_event();
                    }
                }
                d.settle_moved_away(&moved_away);
            }

            // New clients.
            if pfds[1].revents & libc::POLLIN != 0 {
                while let Ok((stream, _)) = listener.accept() {
                    let _ = stream.set_nonblocking(false);
                    let _ = stream.set_read_timeout(Some(Duration::from_secs(5)));
                    let _ = stream.set_write_timeout(Some(Duration::from_secs(5)));
                    clients.push(stream);
                }
            }

            // Client requests (pfds[2..] maps onto clients by index).
            let mut dead: Vec<usize> = Vec::new();
            for (i, pfd) in pfds[2..].iter().enumerate() {
                if pfd.revents & (libc::POLLIN | libc::POLLHUP | libc::POLLERR) == 0 {
                    continue;
                }
                let keep = match ipc::read_frame(&mut clients[i]) {
                    Ok(body) => {
                        let req: Value =
                            serde_json::from_slice(&body).unwrap_or(Value::Null);
                        let ack = d.handle_request(&req);
                        ipc::write_frame(&mut clients[i], ack.to_string().as_bytes()).is_ok()
                    }
                    Err(_) => false, // EOF or protocol error — drop the client
                };
                if !keep {
                    dead.push(i);
                }
            }
            for i in dead.into_iter().rev() {
                clients.remove(i);
            }
        }

        // After the socket, so a writer's own `move` ack has had its say on the
        // node before an unmatched MOVED_FROM is allowed to bury it.
        d.settle_pending_moves();

        metrics::set_heartbeat();
        metrics::set_coherent(true); // re-assert after an extension poison
        if last_resync.elapsed() >= resync {
            d.reconcile();
            last_resync = Instant::now();
        }
    }

    metrics::set_coherent(false);
    let _ = std::fs::remove_file(&d.cfg.socket_path);
    println!("qdbd: stopping (coherence cleared)");
}
