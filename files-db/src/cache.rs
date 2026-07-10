use std::cell::RefCell;
use std::collections::HashMap;
use std::rc::Rc;
use std::time::{Duration, Instant};

use serde_json::Value;

/// Tier-1 per-process cache of decoded documents, keyed by (name, lang) and
/// validated by generation (contract §4): a stale generation is simply a miss.
const MAX_ENTRIES: usize = 2048;

/// Cap on the per-process known-absent set (see the negative cache below).
const NEG_MAX_ENTRIES: usize = 8192;

thread_local! {
    static DOCS: RefCell<HashMap<(String, String), (i64, Rc<Value>)>> =
        RefCell::new(HashMap::new());

    /// Negative cache: names that `resolve_node` proved absent (index miss +
    /// a fruitless docroot walk), with the instant of that proof. A repeat
    /// lookup within the TTL skips the expensive walk and answers "not found"
    /// straight away. Bounded staleness (the TTL) keeps a node created by a
    /// legacy writer discoverable soon; meanwhile the HILI shim's legacy
    /// `find` fallback still locates it, so this never hides an existing node
    /// from the app.
    static NEG: RefCell<HashMap<String, Instant>> = RefCell::new(HashMap::new());
}

pub fn get(name: &str, lang: &str, generation: i64) -> Option<Rc<Value>> {
    let hit = DOCS.with(|c| {
        c.borrow()
            .get(&(name.to_string(), lang.to_string()))
            .filter(|(g, _)| *g == generation)
            .map(|(_, v)| v.clone())
    });
    // A stale generation counts as a miss (contract §4): the entry is unusable.
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
        map.insert((name.to_string(), lang.to_string()), (generation, value));
    });
}

pub fn invalidate(name: &str) {
    DOCS.with(|c| {
        c.borrow_mut().retain(|(n, _), _| n != name);
    });
}

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
