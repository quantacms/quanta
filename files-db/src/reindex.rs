//! Reindex primitives, factored out of `lib.rs` so both the extension and the
//! standalone `qdbwatch` daemon (via `#[path]` include) share one implementation.
//! PHP-free — depends only on `config`, `error`, `index`, `store`.
//!
//! Two operations:
//!   * [`run`] — a full (or subtree) rebuild that also reads documents and bumps
//!     generations. Used by `QuantaDb::reindex()` and the boot warm-up.
//!   * [`reconcile`] — a presence-only sync of the whole tree (node rows + link
//!     rows) that never touches docs or bumps generations, so it does NOT
//!     invalidate the per-worker document caches. Used by the watcher's periodic
//!     safety-net resync and inotify-overflow recovery.

use std::collections::HashSet;
use std::path::Path;

use serde_json::Value;

use crate::config::Config;
use crate::error::DbError;
use crate::index;
use crate::store::{self, WalkNode};

pub struct Counts {
    pub nodes: usize,
    pub links: usize,
}

/// Walk `base` and drop duplicate node names / duplicate links (first wins,
/// mirroring legacy behavior).
fn walk_dedup(cfg: &Config, base: &Path) -> (Vec<WalkNode>, Vec<(String, String)>) {
    let mut nodes = Vec::new();
    let mut links = Vec::new();
    store::walk(cfg, base, &mut nodes, &mut links);
    let mut seen = HashSet::new();
    nodes.retain(|n| seen.insert(n.name.clone()));
    let mut seen_links = HashSet::new();
    links.retain(|l| seen_links.insert(l.clone()));
    (nodes, links)
}

/// Full rebuild under `base`. `full` (base == root) clears every table first;
/// a subtree clears only rows whose path is under `base`. Reads and upserts
/// documents and bumps every node's generation.
pub fn run(cfg: &Config, base: &Path, full: bool) -> Result<Counts, DbError> {
    let (nodes, links) = walk_dedup(cfg, base);

    struct DocEntry {
        name: String,
        lang: String,
        raw: String,
        mtime: i64,
        size: i64,
    }
    let mut docs: Vec<DocEntry> = Vec::new();
    for n in &nodes {
        for lang in store::langs_of(&n.path) {
            if let Ok(Some((raw, fstat))) = store::read_doc(&n.path, &lang) {
                if serde_json::from_str::<Value>(&raw).is_ok() {
                    docs.push(DocEntry {
                        name: n.name.clone(),
                        lang,
                        raw,
                        mtime: fstat.mtime,
                        size: fstat.size,
                    });
                }
            }
        }
    }

    let counts = Counts {
        nodes: nodes.len(),
        links: links.len(),
    };

    index::with(cfg, |c| {
        let tx = c.transaction()?;
        if full {
            tx.execute("DELETE FROM nodes", [])?;
            tx.execute("DELETE FROM docs", [])?;
            tx.execute("DELETE FROM links", [])?;
        } else {
            let old = index::names_by_path_prefix(&tx, &base.to_string_lossy())?;
            for name in &old {
                index::delete_node_cascade(&tx, name)?;
            }
        }
        for n in &nodes {
            index::upsert_node(&tx, &n.name, &n.path.to_string_lossy(), n.father.as_deref())?;
            index::bump(&tx, &n.name)?;
        }
        for d in &docs {
            index::upsert_doc(&tx, &d.name, &d.lang, &d.raw, d.mtime, d.size)?;
        }
        for (container, target) in &links {
            index::insert_link(&tx, container, target)?;
        }
        tx.commit()?;
        Ok(())
    })?;

    Ok(counts)
}

/// Presence-only reconcile of the whole tree against the index: add/refresh
/// node rows for every dir on disk, delete rows for names that vanished, and
/// rebuild the (small) link set. Never reads documents or bumps generations, so
/// live per-worker caches keep validating against unchanged generations. This is
/// the watcher's cheap, cache-safe safety net.
#[allow(dead_code)] // used by the qdbwatch bin (via #[path] include), not the cdylib
pub fn reconcile(cfg: &Config) -> Result<Counts, DbError> {
    let (nodes, links) = walk_dedup(cfg, &cfg.root);
    let on_disk: HashSet<&str> = nodes.iter().map(|n| n.name.as_str()).collect();

    let counts = Counts {
        nodes: nodes.len(),
        links: links.len(),
    };

    index::with(cfg, |c| {
        let tx = c.transaction()?;
        // Drop names no longer on disk.
        for name in index::all_names(&tx)? {
            if !on_disk.contains(name.as_str()) {
                index::delete_node_cascade(&tx, &name)?;
            }
        }
        // Upsert current presence (path/father) WITHOUT bumping generation.
        for n in &nodes {
            index::upsert_node(&tx, &n.name, &n.path.to_string_lossy(), n.father.as_deref())?;
        }
        // Rebuild the link set (few rows; cheap to replace wholesale).
        tx.execute("DELETE FROM links", [])?;
        for (container, target) in &links {
            index::insert_link(&tx, container, target)?;
        }
        tx.commit()?;
        Ok(())
    })?;

    Ok(counts)
}
