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

/// Floor for how long a fallback tree snapshot stays fresh. Short, because
/// fallback is a degraded state, not the design point -- but see `snap_ttl()`:
/// on a large tree the floor alone is a self-inflicted outage, because a walk
/// that costs seconds cannot be repeated every two of them.
const SNAP_TTL_MIN: Duration = Duration::from_secs(2);

/// Ceiling for the adaptive snapshot TTL. Past this the snapshot is too far
/// behind the filesystem to be worth keeping regardless of what it cost.
const SNAP_TTL_MAX: Duration = Duration::from_secs(60);

/// How many times its own build cost a snapshot is allowed to serve for. At 10x
/// the walk occupies at most ~10% of a worker's time, so a permanently absent
/// daemon degrades throughput by a bounded fraction instead of unboundedly.
const SNAP_TTL_COST_FACTOR: u32 = 10;

/// How many consecutive walks may fail to see a name that an earlier walk *did*
/// see before we accept that it is really gone.
///
/// `walk_dedup` is not atomic: it reads one directory at a time, so a walk that
/// races an external rename can read the destination before the move and the
/// source after it, and come back having seen the node in neither. That
/// snapshot is wrong about a node that exists the whole time -- and a wrong
/// "absent" is not a cheap error here, because `resolve_fallback` condemns a
/// name it cannot find into the negative cache for `neg_cache_ms` (30s by
/// default). Carrying the name for a few more walks costs one hash entry and
/// makes that condemnation require an implausible run of consecutive races
/// rather than a single one.
const VANISHED_MAX_MISSES: u32 = 3;

/// Cap on the carried-over set, so a mass delete cannot pin a whole tree's
/// worth of names in memory for three walks.
const VANISHED_MAX_ENTRIES: usize = 8192;

/// Freshness budget for a snapshot that took `cost` to build.
///
/// A fixed 2s TTL assumes the walk is cheap. Once a tree is large enough that a
/// walk costs seconds, that assumption inverts: every worker spends most of its
/// time rebuilding, and requests that should take milliseconds take tens of
/// seconds. Scaling the TTL by the observed cost keeps small trees at the 2s
/// floor (cost ~0) while letting a large one back off automatically, with no
/// tuning knob to get wrong per deployment.
fn snap_ttl(cost: Duration) -> Duration {
    (cost * SNAP_TTL_COST_FACTOR).clamp(SNAP_TTL_MIN, SNAP_TTL_MAX)
}

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
    /// Wall-clock the walk took. Drives both the adaptive TTL and the
    /// rebuild-coalescing window in `snap_rebuild`.
    cost: Duration,
    /// True when this snapshot came from a forced self-heal walk rather than a
    /// TTL refresh. Only such a snapshot may coalesce a further self-heal: see
    /// `snap_coalesce`.
    healed: bool,
    nodes: HashMap<String, SnapEntry>,
    /// Names an earlier walk saw that this one did not, against the number of
    /// consecutive walks that have now missed them. Deliberately kept OUT of
    /// `nodes`: `snap_all_names`/`snap_names_under` enumerate that map to build
    /// `children()` and `find()` results, and a name we are merely unsure about
    /// must not appear in a listing. See `VANISHED_MAX_MISSES`.
    vanished: HashMap<String, u32>,
    links: Vec<(String, String)>,
}

/// Names to carry into a new snapshot as "seen recently, not seen now".
///
/// Split out as a pure function so the policy is testable without a tree: it is
/// what stands between a single racing walk and a 30s negative-cache verdict on
/// a node that never went anywhere.
fn carry_vanished(prev: &Snapshot, seen: &HashMap<String, SnapEntry>) -> HashMap<String, u32> {
    let mut out = HashMap::new();
    let mut push = |name: &String, misses: u32| {
        if !seen.contains_key(name)
            && misses <= VANISHED_MAX_MISSES
            && out.len() < VANISHED_MAX_ENTRIES
        {
            out.insert(name.clone(), misses);
        }
    };
    // A name the previous walk resolved: this is its first miss.
    for name in prev.nodes.keys() {
        push(name, 1);
    }
    // A name already being carried: one more miss, until the run is long enough
    // to believe. `prev.nodes` and `prev.vanished` are disjoint by construction.
    for (name, misses) in &prev.vanished {
        push(name, misses + 1);
    }
    out
}

fn snap_build(cfg: &Config, prev: Option<&Snapshot>) -> Snapshot {
    let started = Instant::now();
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
    let vanished = prev.map(|p| carry_vanished(p, &map)).unwrap_or_default();
    let built = Instant::now();
    Snapshot {
        built,
        cost: built.saturating_duration_since(started),
        healed: false,
        nodes: map,
        vanished,
        links,
    }
}

fn with_fresh_snap<T>(cfg: &Config, f: impl FnOnce(&Snapshot) -> T) -> T {
    SNAP.with(|s| {
        let mut opt = s.borrow_mut();
        let stale = opt
            .as_ref()
            .map(|s| s.built.elapsed() >= snap_ttl(s.cost))
            .unwrap_or(true);
        if stale {
            let next = snap_build(cfg, opt.as_ref());
            *opt = Some(next);
        }
        f(opt.as_ref().expect("snapshot just ensured"))
    })
}

/// Lookup through the TTL-fresh snapshot (builds one when needed).
pub fn snap_lookup(cfg: &Config, name: &str) -> Option<SnapEntry> {
    with_fresh_snap(cfg, |s| s.nodes.get(name).cloned())
}

/// May a forced self-heal walk be skipped, given the current snapshot?
///
/// Split out as a pure function so the policy is testable without a tree or a
/// clock (the same reason `metrics::flap_verdict` is factored out).
///
/// This decides cost only. What decides *correctness* is the caller's
/// `may_coalesce` argument to `snap_rebuild`, and the two must not be confused:
/// coalescing answers a lookup with a walk that finished before the lookup was
/// made, so it may only ever be offered for a name about which this process
/// holds no positive evidence at all. `resolve_fallback` is the judge of that.
///
/// `healed` still gates the first self-heal after a TTL refresh, so a fresh
/// coalescing window always opens on a walk rather than on the TTL snapshot the
/// caller was just failed by.
fn snap_coalesce(healed: bool, age: Duration, cost: Duration) -> bool {
    healed && age < cost
}

/// Force a fresh walk NOW — the self-heal step when a lookup missed or the
/// stored path went stale (external create/move/delete).
///
/// With `may_coalesce`, repeat self-heals are collapsed against the last walk's
/// own cost: a snapshot younger than the time a walk takes is about as fresh as
/// a new walk could make it, so rebuilding answers the same question at the
/// same price. This matters because the caller is a *per-miss* self-heal — one
/// page render that misses thousands of distinct names would otherwise pay for
/// thousands of full tree walks. On a small tree the cost is ~0, no rebuild is
/// ever skipped, and behaviour is unchanged.
///
/// `may_coalesce` is FALSE whenever this process has positive evidence that the
/// node exists — the snapshot holds the name (at a path that has since gone
/// stale), or a recent walk held it and this one did not (`snap_vanished`).
/// Then the walk is not an optimisation to be skipped, it is the whole point of
/// the call: it is the second, independent look that stands between one walk
/// racing an external rename and a 30s negative-cache verdict on a node that is
/// really there. Coalescing that away is what broke `04_concurrency.php`
/// scenario 11 — a reader watching a node shuttle between two fathers saw it
/// vanish for the rest of the run.
pub fn snap_rebuild(cfg: &Config, may_coalesce: bool) {
    SNAP.with(|s| {
        let mut opt = s.borrow_mut();
        if may_coalesce {
            if let Some(cur) = opt.as_ref() {
                if snap_coalesce(cur.healed, cur.built.elapsed(), cur.cost) {
                    return;
                }
            }
        }
        let mut snap = snap_build(cfg, opt.as_ref());
        snap.healed = true;
        *opt = Some(snap);
    });
}

/// Did a recent walk see `name` that the current snapshot does not?
///
/// Positive evidence that the node exists, held for `VANISHED_MAX_MISSES`
/// walks. Read without freshness handling: the caller has just been through
/// `snap_lookup`, so the snapshot is as fresh as its TTL requires.
pub fn snap_vanished(name: &str) -> bool {
    SNAP.with(|s| {
        s.borrow()
            .as_ref()
            .is_some_and(|s| s.vanished.contains_key(name))
    })
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
            snap.vanished.remove(name);
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
            // A delete this process performed is not a walk that lost sight of
            // the name: it is proof of absence, so it must not be carried.
            snap.nodes.remove(name);
            snap.vanished.remove(name);
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

#[cfg(test)]
mod snap_policy_tests {
    use super::*;

    #[test]
    fn a_cheap_walk_keeps_the_floor() {
        // Small trees must behave exactly as before: the 2s floor, unchanged.
        assert_eq!(snap_ttl(Duration::ZERO), SNAP_TTL_MIN);
        assert_eq!(snap_ttl(Duration::from_millis(1)), SNAP_TTL_MIN);
        assert_eq!(snap_ttl(Duration::from_millis(199)), SNAP_TTL_MIN);
    }

    #[test]
    fn an_expensive_walk_backs_off() {
        // Past the floor the snapshot serves for 10x what it cost, so the walk
        // stays a bounded fraction of a worker's time instead of all of it.
        assert_eq!(snap_ttl(Duration::from_secs(1)), Duration::from_secs(10));
        assert_eq!(snap_ttl(Duration::from_secs(3)), Duration::from_secs(30));
    }

    #[test]
    fn the_backoff_is_capped() {
        // However expensive the walk, a snapshot this old is not worth keeping.
        assert_eq!(snap_ttl(Duration::from_secs(30)), SNAP_TTL_MAX);
        assert_eq!(snap_ttl(Duration::from_secs(600)), SNAP_TTL_MAX);
    }

    #[test]
    fn the_first_self_heal_after_a_ttl_refresh_always_walks() {
        // The caller has just been failed by this very snapshot, so reusing it
        // would answer a miss with the evidence that produced the miss.
        let cost = Duration::from_secs(5);
        assert!(!snap_coalesce(false, Duration::ZERO, cost));
        assert!(!snap_coalesce(false, cost * 2, cost));
    }

    fn snap_of(nodes: &[&str], vanished: &[(&str, u32)]) -> Snapshot {
        Snapshot {
            built: Instant::now(),
            cost: Duration::ZERO,
            healed: false,
            nodes: nodes
                .iter()
                .map(|n| {
                    (
                        (*n).to_string(),
                        SnapEntry {
                            path: PathBuf::from("/tmp").join(n),
                            father: None,
                        },
                    )
                })
                .collect(),
            vanished: vanished
                .iter()
                .map(|(n, m)| ((*n).to_string(), *m))
                .collect(),
            links: Vec::new(),
        }
    }

    fn seen_of(nodes: &[&str]) -> HashMap<String, SnapEntry> {
        snap_of(nodes, &[]).nodes
    }

    #[test]
    fn a_name_a_walk_stopped_seeing_is_carried() {
        // The `04_concurrency.php` scenario 11 shape: `walk_dedup` reads the
        // destination before an external move and the source after it, so a
        // node that never left the tree is in neither. One such walk must not
        // be enough to lose the name.
        let prev = snap_of(&["home", "shuttle"], &[]);
        let carried = carry_vanished(&prev, &seen_of(&["home"]));
        assert_eq!(carried.get("shuttle"), Some(&1));
        assert!(
            !carried.contains_key("home"),
            "a name this walk saw is not in doubt"
        );
    }

    #[test]
    fn a_name_a_walk_found_again_is_no_longer_carried() {
        let prev = snap_of(&["home"], &[("shuttle", 2)]);
        let carried = carry_vanished(&prev, &seen_of(&["home", "shuttle"]));
        assert!(carried.is_empty());
    }

    #[test]
    fn a_name_no_walk_can_find_is_eventually_released() {
        // Otherwise a genuinely deleted node would force a full walk on every
        // lookup of its name, forever: the doubt has to have an end.
        let seen = seen_of(&["home"]);
        let mut misses = 1;
        let mut prev = snap_of(&["home", "gone"], &[]);
        loop {
            let carried = carry_vanished(&prev, &seen);
            match carried.get("gone") {
                Some(m) => {
                    assert_eq!(*m, misses);
                    misses += 1;
                    assert!(misses <= VANISHED_MAX_MISSES + 1, "carried forever");
                    prev = snap_of(&["home"], &[("gone", *m)]);
                }
                None => break,
            }
        }
        assert_eq!(misses, VANISHED_MAX_MISSES + 1);
    }

    #[test]
    fn the_carried_set_is_capped() {
        // A mass delete must not pin a whole tree's worth of names in memory.
        let names: Vec<String> = (0..VANISHED_MAX_ENTRIES + 100)
            .map(|i| format!("n{i}"))
            .collect();
        let prev = snap_of(&names.iter().map(String::as_str).collect::<Vec<_>>(), &[]);
        assert_eq!(
            carry_vanished(&prev, &HashMap::new()).len(),
            VANISHED_MAX_ENTRIES
        );
    }

    #[test]
    fn repeat_self_heals_within_one_walk_are_coalesced() {
        // The case this exists for: a render missing thousands of names must
        // buy one tree walk, not thousands.
        let cost = Duration::from_secs(5);
        assert!(snap_coalesce(true, Duration::ZERO, cost));
        assert!(snap_coalesce(true, Duration::from_secs(4), cost));
        // Past one walk's worth of age a new walk really can say something new.
        assert!(!snap_coalesce(true, cost, cost));
        assert!(!snap_coalesce(true, Duration::from_secs(9), cost));
    }

    #[test]
    fn a_cheap_walk_is_never_coalesced() {
        // Small trees keep the old unconditional-rebuild behaviour exactly:
        // cost ~0 means no age is ever below it.
        assert!(!snap_coalesce(true, Duration::ZERO, Duration::ZERO));
        assert!(!snap_coalesce(true, Duration::from_nanos(1), Duration::ZERO));
    }
}
