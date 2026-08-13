//! Per-process caches. In SHM mode only the decoded-document parse cache is
//! active (pure parse-avoidance, validated by generation — it can never serve
//! stale data). Everything else here exists for FALLBACK mode (daemon down):
//! the negative cache, the tree snapshot that replaces the old SQLite index,
//! and the write-generation map.

use std::cell::RefCell;
use std::collections::HashMap;
use std::path::PathBuf;
use std::rc::Rc;
use std::time::{Duration, Instant};

use serde_json::Value;

use crate::config::Config;
use crate::model;

/// Tier-1 per-process cache of decoded documents, keyed by (name, lang) and
/// validated by generation (contract §4): a stale generation is simply a miss.
const MAX_ENTRIES: usize = 2048;

/// Cap on the per-process known-absent set (see the negative cache below).
const NEG_MAX_ENTRIES: usize = 8192;

/// How long a fallback tree snapshot stays fresh before the next read rebuilds
/// it. Short: fallback mode is a degraded state, not the design point.
const SNAP_TTL: Duration = Duration::from_secs(2);

/// One parse-cache entry. Keyed by the hashes of (name, lang) so a lookup costs
/// no allocation — the previous `HashMap<(String, String), _>` built two owned
/// `String`s on every read just to ask the question. The names are kept in the
/// entry and re-checked on a hit, so a hash collision is a miss, never a wrong
/// document.
struct DocEntry {
    name: String,
    lang: String,
    generation: i64,
    value: Rc<Value>,
}

fn doc_key(name: &str, lang: &str) -> (u64, u64) {
    (
        crate::shm::fnv1a(name.as_bytes()),
        crate::shm::fnv1a(lang.as_bytes()),
    )
}

thread_local! {
    static DOCS: RefCell<HashMap<(u64, u64), DocEntry>> =
        RefCell::new(HashMap::new());

    /// Negative cache: names proven absent (snapshot miss + a fruitless
    /// docroot walk), with the instant of that proof. A repeat lookup within
    /// the TTL skips the walk. Bounded staleness keeps a node created by a
    /// legacy writer discoverable soon; meanwhile the Quanta shim's legacy
    /// `find` fallback still locates it, so this never hides an existing node
    /// from the app. Fallback mode only.
    static NEG: RefCell<HashMap<String, Instant>> = RefCell::new(HashMap::new());

    /// Fallback tree snapshot: one docroot walk's worth of name -> (path,
    /// father) plus the link edges — the replacement for the old SQLite index
    /// when no daemon is serving. Rebuilt on TTL expiry and on any miss (the
    /// self-heal walk, contract §4.3).
    static SNAP: RefCell<Option<Snapshot>> = const { RefCell::new(None) };

    /// Generations of nodes written BY THIS PROCESS while in fallback mode
    /// (the daemon owns generations otherwise). Values come from the shared
    /// counter so they stay monotonic across modes.
    static GENS: RefCell<HashMap<String, i64>> = RefCell::new(HashMap::new());
}

// ---------------------------------------------------------------------------
// Decoded-document parse cache
// ---------------------------------------------------------------------------

pub fn get(name: &str, lang: &str, generation: i64) -> Option<Rc<Value>> {
    let hit = DOCS.with(|c| {
        c.borrow()
            .get(&doc_key(name, lang))
            // A stale generation counts as a miss (contract §4): the entry is
            // unusable. The name/lang re-check makes a hash collision a miss.
            .filter(|e| e.generation == generation && e.name == name && e.lang == lang)
            .map(|e| e.value.clone())
    });
    if hit.is_some() {
        crate::metrics::cache_hit();
    } else {
        crate::metrics::cache_miss();
    }
    hit
}

pub fn put(name: &str, lang: &str, generation: i64, value: Rc<Value>) {
    DOCS.with(|c| {
        let mut map = c.borrow_mut();
        if map.len() >= MAX_ENTRIES {
            map.clear();
        }
        map.insert(
            doc_key(name, lang),
            DocEntry {
                name: name.to_string(),
                lang: lang.to_string(),
                generation,
                value,
            },
        );
    });
}

pub fn invalidate(name: &str) {
    DOCS.with(|c| {
        c.borrow_mut().retain(|_, e| e.name != name);
    });
}

pub fn clear_docs() {
    DOCS.with(|c| c.borrow_mut().clear());
}

// ---------------------------------------------------------------------------
// Negative cache (fallback mode)
// ---------------------------------------------------------------------------

/// True if `name` is known-absent and that proof is younger than `ttl`.
/// A fresh hit bumps `neg_hits` (a saved docroot walk); stale/absent is a miss.
pub fn neg_fresh(name: &str, ttl: Duration) -> bool {
    let fresh = NEG.with(|c| matches!(c.borrow().get(name), Some(t) if t.elapsed() < ttl));
    if fresh {
        crate::metrics::neg_hit();
    }
    fresh
}

/// Record that `name` resolved to nothing, so a repeat lookup can skip the walk.
pub fn neg_insert(name: &str) {
    NEG.with(|c| {
        let mut m = c.borrow_mut();
        if m.len() >= NEG_MAX_ENTRIES {
            m.clear();
        }
        m.insert(name.to_string(), Instant::now());
    });
}

/// Drop `name` from the negative cache (it now exists / may exist).
pub fn neg_remove(name: &str) {
    NEG.with(|c| {
        c.borrow_mut().remove(name);
    });
}

/// Forget every known-absent name (e.g. after a reindex rebuilds the tree).
pub fn neg_clear() {
    NEG.with(|c| c.borrow_mut().clear());
}

// ---------------------------------------------------------------------------
// Fallback tree snapshot
// ---------------------------------------------------------------------------

#[derive(Clone)]
pub struct SnapEntry {
    pub path: PathBuf,
    pub father: Option<String>,
}

struct Snapshot {
    built: Instant,
    nodes: HashMap<String, SnapEntry>,
    links: Vec<(String, String)>,
}

fn snap_build(cfg: &Config) -> Snapshot {
    let (nodes, links) = model::walk_dedup(cfg, &cfg.root);
    let mut map = HashMap::with_capacity(nodes.len());
    for n in nodes {
        map.insert(
            n.name,
            SnapEntry {
                path: n.path,
                father: n.father,
            },
        );
    }
    Snapshot {
        built: Instant::now(),
        nodes: map,
        links,
    }
}

fn with_fresh_snap<T>(cfg: &Config, f: impl FnOnce(&Snapshot) -> T) -> T {
    SNAP.with(|s| {
        let mut opt = s.borrow_mut();
        let stale = opt
            .as_ref()
            .map(|s| s.built.elapsed() >= SNAP_TTL)
            .unwrap_or(true);
        if stale {
            *opt = Some(snap_build(cfg));
        }
        f(opt.as_ref().expect("snapshot just ensured"))
    })
}

/// Lookup through the TTL-fresh snapshot (builds one when needed).
pub fn snap_lookup(cfg: &Config, name: &str) -> Option<SnapEntry> {
    with_fresh_snap(cfg, |s| s.nodes.get(name).cloned())
}

/// Force a fresh walk NOW — the self-heal step when a lookup missed or the
/// stored path went stale (external create/move/delete).
pub fn snap_rebuild(cfg: &Config) {
    SNAP.with(|s| *s.borrow_mut() = Some(snap_build(cfg)));
}

/// Lookup without freshness handling (right after `snap_rebuild`).
pub fn snap_lookup_raw(name: &str) -> Option<SnapEntry> {
    SNAP.with(|s| s.borrow().as_ref().and_then(|s| s.nodes.get(name).cloned()))
}

/// Containers holding a symlink to `target`, sorted (fallback `links()`).
pub fn snap_links_of(cfg: &Config, target: &str) -> Vec<String> {
    let mut v = with_fresh_snap(cfg, |s| {
        s.links
            .iter()
            .filter(|(_, t)| t == target)
            .map(|(c, _)| c.clone())
            .collect::<Vec<_>>()
    });
    v.sort();
    v.dedup();
    v
}

/// All node names, sorted (fallback `find` universe).
pub fn snap_all_names(cfg: &Config) -> Vec<String> {
    let mut v = with_fresh_snap(cfg, |s| s.nodes.keys().cloned().collect::<Vec<_>>());
    v.sort();
    v
}

/// Root-relative-path lookup base for `lineage` (fallback).
pub fn snap_names_under(cfg: &Config, base: &std::path::Path) -> Vec<String> {
    let mut v = with_fresh_snap(cfg, |s| {
        s.nodes
            .iter()
            .filter(|(_, e)| e.path.starts_with(base) && e.path != base)
            .map(|(n, _)| n.clone())
            .collect::<Vec<_>>()
    });
    v.sort();
    v
}

/// Keep an existing snapshot coherent with this process's own writes (never
/// forces a walk — a missing snapshot just learns the truth on its next build).
pub fn snap_note_put(name: &str, path: &std::path::Path, father: Option<&str>) {
    SNAP.with(|s| {
        if let Some(snap) = s.borrow_mut().as_mut() {
            snap.nodes.insert(
                name.to_string(),
                SnapEntry {
                    path: path.to_path_buf(),
                    father: father.map(str::to_string),
                },
            );
        }
    });
}

pub fn snap_remove(name: &str) {
    SNAP.with(|s| {
        if let Some(snap) = s.borrow_mut().as_mut() {
            snap.nodes.remove(name);
            snap.links.retain(|(c, t)| c != name && t != name);
        }
    });
}

/// Add or remove one (container, target) link edge in the live snapshot.
pub fn snap_link_set(container: &str, target: &str, present: bool) {
    SNAP.with(|s| {
        if let Some(snap) = s.borrow_mut().as_mut() {
            let edge = (container.to_string(), target.to_string());
            if present {
                if !snap.links.contains(&edge) {
                    snap.links.push(edge);
                }
            } else {
                snap.links.retain(|e| *e != edge);
            }
        }
    });
}

pub fn snap_invalidate() {
    SNAP.with(|s| *s.borrow_mut() = None);
}

// ---------------------------------------------------------------------------
// Fallback write generations
// ---------------------------------------------------------------------------

/// Allocate the next generation for a node written in fallback mode and
/// remember it so `meta()` keeps reporting increasing values in-process.
pub fn gen_note_write(name: &str) -> i64 {
    let g = crate::metrics::next_generation() as i64;
    GENS.with(|c| {
        c.borrow_mut().insert(name.to_string(), g);
    });
    g
}

/// Last known fallback generation for a node (1 when never written here —
/// matching the old index's initial generation).
pub fn gen_of(name: &str) -> i64 {
    GENS.with(|c| c.borrow().get(name).copied().unwrap_or(1))
}
