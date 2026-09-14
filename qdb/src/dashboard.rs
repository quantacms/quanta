//! `qdbstat --dashboard` — a read-only web browser for what the qdb
//! actually holds.
//!
//! The terminal dashboard and `/metrics` answer "is the index healthy"; this
//! answers "what is *in* it" — every node, its position in the tree, its links,
//! and the document files themselves, rendered from the segment.
//!
//! Three properties shape the whole module:
//!
//!   * **The segment is the only source.** Not the filesystem. A dashboard that
//!     walked the disk when the daemon was down would keep showing content
//!     while silently answering a different question — "what is on disk" rather
//!     than "what is served". When there is no segment it says so and shows
//!     nothing else. That is the useful answer, not a degraded one.
//!   * **Read-only by construction.** The mapping is `PROT_READ`, no route
//!     mutates, and node names arriving from a query string are only ever hash
//!     keys for `SegmentReader::lookup` — they never become a path, so there is
//!     no traversal surface to get wrong.
//!   * **It serves content, not counters.** `/metrics` deliberately exposes no
//!     paths and no document bodies; this exposes both. Hence a loopback
//!     default and the optional shared-secret check below, where the exporter
//!     needs neither.
//!
//! Every request opens the segment afresh (see `View::open`). That costs an
//! open + mmap + munmap and buys the two things a cached mapping cannot give:
//! no epoch flip can happen *inside* a request, and a compaction can never
//! leave the server answering from a segment nobody writes to any more.
#![allow(dead_code)]

use std::io::{BufRead, BufReader, Read, Write};
use std::net::{TcpListener, TcpStream};
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;
use std::time::Duration;

use serde_json::{json, Map, Value};

use crate::image;
use crate::metrics;
use crate::shm::{self, Lookup, RecordView, SegmentReader};

/// Loopback, unlike the exporter's `0.0.0.0`, and for the reason in the module
/// docs: the payload here is site content. `kubectl port-forward` reaches it
/// without opening it to the cluster, and widening it to `0.0.0.0` stays
/// possible but has to be typed.
///
/// Port 9110 sits next to the exporter's 9109 and clear of nginx (80) and
/// php-fpm (9000).
pub const DEFAULT_LISTEN: &str = "127.0.0.1:9110";

/// Connections served at once. A browser opens several per page, so the
/// exporter's single-threaded loop would serialise a page load behind its own
/// asset requests; a cap keeps that from becoming an unbounded thread spawner.
const MAX_INFLIGHT: usize = 16;

/// Both directions, as the exporter sets: a slow client must not wedge a
/// worker for longer than its own patience.
const IO_TIMEOUT: Duration = Duration::from_secs(15);

/// Documents larger than this are described but not rendered. The browser
/// would not usefully display them either, and building the response means
/// holding the decoded value plus its serialisation in the worker's heap.
const MAX_DOC_BYTES: i64 = 4 << 20;

/// Page size ceiling for `/api/list`. The scan itself is cheap; the response is
/// what has to stay a page rather than a database dump.
const LIST_MAX_LIMIT: usize = 1000;
const LIST_DEFAULT_LIMIT: usize = 200;

/// Request-line and header limits. Neither is a security boundary on its own —
/// the timeouts are — but they keep one connection from reading forever.
const MAX_LINE: usize = 8 * 1024;
const MAX_HEADERS: usize = 64;

const INDEX_HTML: &str = include_str!("dashboard/index.html");
const APP_CSS: &str = include_str!("dashboard/app.css");
const APP_JS: &str = include_str!("dashboard/app.js");

/// Everything a worker needs; cloned into each connection thread by `Arc`.
pub struct Config {
    pub addr: String,
    pub shm_path: PathBuf,
    pub data_dir: PathBuf,
    /// Shared secret required on every route but `/healthz`, or `None` for an
    /// unauthenticated dashboard (announced at startup).
    pub token: Option<String>,
    /// URL prefix this dashboard is reached at, normalised by
    /// [`normalize_base`]: `""` at the root, or `"/qdb"` — leading slash, no
    /// trailing one.
    ///
    /// Needed because an Ingress that routes a path to a backend does NOT strip
    /// that path: mounted at `/qdb`, every request arrives spelled
    /// `/qdb/api/node`, and a server that only knows its own routes answers 404
    /// to all of them. Prefix-stripping middleware is the other way round, but
    /// it is per-controller configuration and it still leaves the missing
    /// trailing slash broken — `/qdb` would resolve the page's own `app.js` to
    /// `/app.js`, outside the prefix. Knowing the prefix here fixes both, and
    /// keeps the deployment's ingress plain.
    pub base_path: String,
}

/// Normalise a base path: `""`, `"/"`, `"qdb"`, `"/qdb/"` all mean the same two
/// things — root, or the prefix `/qdb` with a leading slash and no trailing
/// one. Every comparison below assumes that shape.
#[must_use]
pub fn normalize_base(raw: &str) -> String {
    let t = raw.trim().trim_matches('/');
    if t.is_empty() {
        String::new()
    } else {
        format!("/{t}")
    }
}

/// Produces the `/api/stats` body: the same JSON `qdbstat --json` prints.
///
/// Passed in rather than reached for, because that snapshot is assembled from
/// `qdbstat`'s own verdict/formatting code and this module has no business
/// depending on the binary that hosts it.
pub type StatsFn = fn(&Path, &Path) -> String;

// ---------------------------------------------------------------------------
// Arena + segment access
// ---------------------------------------------------------------------------

/// An owned mapping of the counter arena, unmapped on drop.
///
/// `qdbstat`'s one-shot `open_arena` leaks its mapping deliberately — the
/// process is about to exit. A server cannot: it maps once per request, and a
/// leaked page per request is a leak per request.
///
/// Like `ArenaWatch`, it re-opens unconditionally rather than trusting the
/// path's identity; the overlayfs copy-up described there makes device+inode a
/// signal that is stable precisely when the contents have diverged.
pub struct ArenaMap(Option<&'static metrics::Metrics>);

impl ArenaMap {
    pub fn open(path: &Path) -> Self {
        // Safety: `map_file` maps the arena read-only; the reference is kept
        // only until `Drop` unmaps it, and is never handed out beyond `&self`.
        let Ok(p) = (unsafe { metrics::map_file(path, false, false) }) else {
            return ArenaMap(None);
        };
        // An arena seen mid-initialisation still mapped a page, and dropping
        // the reference does not give it back. Unmap here rather than letting
        // "not ready yet" leak a page per request for as long as it lasts.
        if !metrics::is_ready(unsafe { &*p }) {
            unsafe { metrics::unmap(p) };
            return ArenaMap(None);
        }
        ArenaMap(Some(unsafe { &*p }))
    }

    pub fn get(&self) -> Option<&metrics::Metrics> {
        self.0
    }

    pub fn snapshot(&self) -> metrics::Snapshot {
        self.0.map(metrics::Metrics::snapshot).unwrap_or_default()
    }
}

impl Drop for ArenaMap {
    fn drop(&mut self) {
        if let Some(m) = self.0.take() {
            // Safety: `m` came from `map_file` above and nothing else holds it.
            unsafe { metrics::unmap(m as *const _ as *mut _) };
        }
    }
}

/// Every epoch with a segment file in `dir`, newest first.
///
/// The arena names the active epoch, but the arena is optional
/// (`quanta_db.metrics=off`) and may not exist at all on a box where only the
/// daemon has run. The directory still does, so fall back to it rather than
/// telling the operator there is no index when there plainly is one.
///
/// All of them, newest first, rather than just the highest: a compaction
/// creates the new epoch's file and fills it before publishing `ready`, so for
/// the length of that fill the highest-numbered file on disk is one no reader
/// may use. Taking only the maximum would blank the dashboard for the duration
/// of every compaction — exactly when someone is most likely to be watching.
/// `SegmentReader::open` is the arbiter; this only supplies candidates.
fn epochs_newest_first(dir: &Path) -> Vec<u64> {
    let Ok(rd) = std::fs::read_dir(dir) else { return Vec::new() };
    let mut found: Vec<u64> = Vec::new();
    for e in rd.flatten() {
        let name = e.file_name();
        let Some(name) = name.to_str() else { continue };
        let Some(rest) = name.strip_prefix("data.") else { continue };
        let Some(num) = rest.strip_suffix(".shm") else { continue };
        if let Ok(n) = num.parse::<u64>() {
            found.push(n);
        }
    }
    found.sort_unstable_by(|a, b| b.cmp(a));
    found
}

/// One request's consistent view of the index.
struct View {
    seg: Option<SegmentReader>,
    snap: metrics::Snapshot,
    arena_ok: bool,
}

impl View {
    fn open(cfg: &Config) -> View {
        let arena = ArenaMap::open(&cfg.shm_path);
        let snap = arena.snapshot();
        let arena_ok = arena.get().is_some();
        // The arena's epoch first, because it is the one PHP is actually
        // reading; the directory only answers when there is no arena, or when
        // the epoch it names has already been unlinked under us.
        let seg = Some(snap.data_epoch)
            .filter(|e| *e != 0)
            .and_then(|e| SegmentReader::open(&cfg.data_dir, e, 0).ok())
            .or_else(|| {
                epochs_newest_first(&cfg.data_dir)
                    .into_iter()
                    .find_map(|e| SegmentReader::open(&cfg.data_dir, e, 0).ok())
            });
        View { seg, snap, arena_ok }
    }

    /// The segment, or the 503 body explaining why there isn't one.
    ///
    /// A missing segment is not a client error and not a server fault: it means
    /// the daemon has not published yet, or is gone and the app is on the
    /// fallback path. 503 is the status that says "ask again later", which is
    /// exactly the situation.
    fn segment(&self) -> Result<&SegmentReader, Response> {
        self.seg.as_ref().ok_or_else(|| {
            let why = if !self.arena_ok {
                "no metrics arena and no segment file: the daemon has not published, \
                 or quanta_db.metrics is off and qdbd has never run for this root"
            } else if self.snap.data_epoch == 0 {
                "the arena reports no active epoch: qdbd has not published a segment yet"
            } else {
                "the segment named by the arena could not be mapped (mid-compaction, \
                 or a stale epoch); retry"
            };
            json_response(503, &json!({ "error": "no segment", "detail": why }))
        })
    }
}

// ---------------------------------------------------------------------------
// Node projection
// ---------------------------------------------------------------------------

/// A node's on-disk document file name, mirroring `store::doc_file` — the
/// neutral document is `data.json`, a translation is `data_<lang>.json`.
///
/// Duplicated rather than included because `store` pulls in the config and the
/// whole write path; this is three lines of naming convention.
fn doc_file(lang: &str) -> String {
    if lang.is_empty() {
        "data.json".to_string()
    } else {
        format!("data_{lang}.json")
    }
}

/// Every language a record carries, as the UI shows it: the file it came from,
/// how big it is on disk, and which of the two in-segment representations
/// holds it.
fn langs_json(rec: &RecordView<'_>) -> Value {
    let mut out = Vec::new();
    for l in rec.langs() {
        out.push(json!({
            "lang": l.lang,
            "file": doc_file(l.lang),
            "size": l.doc_size,
            "mtime": l.doc_mtime,
            "corrupt": l.flags & shm::LANG_CORRUPT != 0,
            // Which representation is resident. Exactly one of these is true
            // for a healthy document: an imaged document drops its raw bytes,
            // and an un-imaged one keeps them (see `model::DocModel::raw`).
            "imaged": l.flags & shm::LANG_HAS_IMAGE != 0,
            "raw": l.flags & shm::LANG_HAS_RAW != 0,
            "img_bytes": l.img.len(),
            "raw_bytes": l.doc.len(),
        }));
    }
    Value::Array(out)
}

/// Summary of one node, as the tree and the list both render it.
fn summary_json(rec: &RecordView<'_>) -> Value {
    let langs = rec.langs();
    let doc_bytes: i64 = langs.iter().map(|l| l.doc_size.max(0)).sum();
    let children = rec.children();
    json!({
        "name": rec.name(),
        "rel_path": rec.rel_path(),
        "father": rec.father(),
        "mtime": rec.mtime,
        "children": children.len(),
        // Symlinked children are containers pointing elsewhere; the tree draws
        // them differently, so the count has to be separable.
        "child_links": children.iter().filter(|(_, link)| *link).count(),
        "inlinks": rec.inlinks().len(),
        "langs": langs.iter().map(|l| l.lang).collect::<Vec<_>>(),
        "files": langs.iter().map(|l| doc_file(l.lang)).collect::<Vec<_>>(),
        "doc_bytes": doc_bytes,
        "corrupt": langs.iter().any(|l| l.flags & shm::LANG_CORRUPT != 0),
    })
}

/// A child entry, resolved through its own lookup so the tree knows whether it
/// can be expanded without a round trip per row.
///
/// `kind` is the distinction that matters, and it is not cosmetic:
///
///   * `node` — a directory the daemon indexed. Expandable, clickable.
///   * `symlink` — a symlink whose own filename is not a node name. A link row
///     is recorded as `(container, TARGET NAME)` (`store::walk`), so the
///     symlink's filename is deliberately not in the index and looking it up
///     finds nothing. The target is reachable the other way round — see
///     `links_out` — never through this name.
///   * `missing` — a real child directory that is NOT in the index. This one is
///     drift, and it is exactly what someone opens this dashboard to find, so
///     it is surfaced rather than filtered out.
///
/// Collapsing `symlink` into `missing` would label every link in the tree as
/// broken, which is why the two are separate.
fn child_json(seg: &SegmentReader, name: &str, is_link: bool) -> Value {
    match seg.lookup(name) {
        Lookup::Found(rec) if !rec.is_tombstone() => {
            let mut v = summary_json(&rec);
            if let Value::Object(ref mut m) = v {
                m.insert("link".into(), Value::Bool(is_link));
                m.insert("kind".into(), json!("node"));
            }
            v
        }
        _ if is_link => json!({ "name": name, "link": true, "kind": "symlink" }),
        _ => json!({ "name": name, "link": false, "kind": "missing" }),
    }
}

/// Nodes this one links TO.
///
/// The segment stores the edge only on the target (`inlinks`: "container names
/// symlinking to this node"), so the outgoing direction has to be scanned for.
/// Without it the link graph is navigable in one direction only — you could see
/// what points at a node, never what it points at — and the tree's symlink rows
/// would be dead ends.
fn links_out(seg: &SegmentReader, name: &str) -> Vec<Value> {
    let mut out = Vec::new();
    seg.for_each_live(|rec| {
        if rec.inlinks().iter().any(|c| *c == name) {
            out.push(summary_json(rec));
        }
    });
    out.sort_by(|a, b| sort_key(a).cmp(&sort_key(b)));
    out
}

fn node_json(seg: &SegmentReader, rec: &RecordView<'_>) -> Value {
    let children: Vec<Value> = rec
        .children()
        .into_iter()
        .map(|(n, link)| child_json(seg, n, link))
        .collect();
    // Inlink entries are container node names, so they always resolve.
    let inlinks: Vec<Value> = rec
        .inlinks()
        .into_iter()
        .map(|n| child_json(seg, n, false))
        .collect();
    json!({
        "name": rec.name(),
        "rel_path": rec.rel_path(),
        "father": rec.father(),
        "generation": rec.generation,
        "mtime": rec.mtime,
        "children": children,
        "inlinks": inlinks,
        "links_out": links_out(seg, rec.name()),
        "langs": langs_json(rec),
    })
}

// ---------------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------------

/// Nodes with no father: the tree's top level.
///
/// Plural, and not because a tree has several roots — because an orphan (a node
/// whose father left the index) presents exactly this way, and hiding it would
/// hide the only view that shows it.
fn route_roots(view: &View) -> Result<Response, Response> {
    let seg = view.segment()?;
    let mut roots = Vec::new();
    let mut total = 0u64;
    seg.for_each_live(|rec| {
        total += 1;
        if rec.father().is_none() {
            roots.push(summary_json(rec));
        }
    });
    roots.sort_by(|a, b| sort_key(a).cmp(&sort_key(b)));
    Ok(json_response(
        200,
        &json!({ "epoch": seg.epoch, "total": total, "roots": roots }),
    ))
}

fn sort_key(v: &Value) -> String {
    v.get("rel_path")
        .or_else(|| v.get("name"))
        .and_then(Value::as_str)
        .unwrap_or_default()
        .to_string()
}

fn route_node(view: &View, q: &Query) -> Result<Response, Response> {
    let seg = view.segment()?;
    let Some(name) = q.get("name") else {
        return Err(bad_request("name is required"));
    };
    match seg.lookup(&name) {
        Lookup::Found(rec) if !rec.is_tombstone() => {
            Ok(json_response(200, &node_json(seg, &rec)))
        }
        Lookup::Invalid => Err(json_response(
            503,
            &json!({ "error": "unreadable record", "name": name }),
        )),
        _ => Err(json_response(404, &json!({ "error": "no such node", "name": name }))),
    }
}

/// One document, decoded out of the segment.
///
/// Both representations are served from shared memory, never from disk: an
/// imaged document is walked back into a `Value` (`image::Image::to_value`) and
/// an un-imaged one carries its exact bytes. A document that is neither — too
/// large to image, and its raw dropped — is reported as absent from the
/// segment rather than fetched, because fetching it would make this the one
/// route that answers from the filesystem.
fn route_doc(view: &View, q: &Query) -> Result<Response, Response> {
    let seg = view.segment()?;
    let Some(name) = q.get("name") else {
        return Err(bad_request("name is required"));
    };
    let lang = q.get("lang").unwrap_or_default();

    let Lookup::Found(rec) = seg.lookup(&name) else {
        return Err(json_response(404, &json!({ "error": "no such node", "name": name })));
    };
    if rec.is_tombstone() {
        return Err(json_response(404, &json!({ "error": "no such node", "name": name })));
    }
    let langs = rec.langs();
    let Some(l) = langs.iter().find(|l| l.lang == lang) else {
        return Err(json_response(
            404,
            &json!({ "error": "no such language on this node", "name": name, "lang": lang }),
        ));
    };

    let mut out = Map::new();
    out.insert("name".into(), json!(name));
    out.insert("lang".into(), json!(lang));
    out.insert("file".into(), json!(doc_file(lang.as_str())));
    out.insert("size".into(), json!(l.doc_size));
    out.insert("mtime".into(), json!(l.doc_mtime));
    out.insert("rel_path".into(), json!(rec.rel_path()));

    if l.flags & shm::LANG_CORRUPT != 0 {
        out.insert("source".into(), json!("corrupt"));
        out.insert(
            "detail".into(),
            json!("the daemon could not parse this file; nothing is resident for it"),
        );
    } else if l.doc_size > MAX_DOC_BYTES {
        out.insert("source".into(), json!("too_large"));
        out.insert("detail".into(), json!(format!("document exceeds the {MAX_DOC_BYTES} byte render limit")));
    } else if !l.img.is_empty() {
        match image::Image::open(l.img, seg.bytes()).and_then(|img| img.to_value(img.root, 0)) {
            Some(v) => {
                out.insert("source".into(), json!("image"));
                out.insert("doc".into(), v);
            }
            None => {
                out.insert("source".into(), json!("unreadable"));
                out.insert("detail".into(), json!("the document image could not be decoded"));
            }
        }
    } else if !l.doc.is_empty() {
        out.insert("source".into(), json!("raw"));
        match serde_json::from_slice::<Value>(l.doc) {
            Ok(v) => {
                out.insert("doc".into(), v);
            }
            Err(e) => {
                // The bytes are in the segment and the browser should still see
                // them; only the structured view is unavailable.
                out.insert("source".into(), json!("raw_text"));
                out.insert("detail".into(), json!(e.to_string()));
                out.insert("text".into(), json!(String::from_utf8_lossy(l.doc)));
            }
        }
    } else {
        out.insert("source".into(), json!("not_resident"));
        out.insert(
            "detail".into(),
            json!("this document has neither an image nor raw bytes in the segment \
                   (over quanta_db.image_max_doc_kb, or images are off); the file on \
                   disk is the only copy"),
        );
    }
    Ok(json_response(200, &Value::Object(out)))
}

/// Every node, filtered and paginated — the "show me all of it" view.
///
/// The only route that scans, and the one that has to stay cheap on a real
/// tree: hili's index is ~200k nodes, and the first thing anyone clicks is this.
///
/// So the scan keeps a bounded max-heap of the `offset + limit` smallest sort
/// keys rather than collecting every match and sorting it. Two things fall out
/// of that, and together they are the whole cost of the route:
///
///   * only the keys that are still in the window are ever allocated — a
///     record is compared as a borrowed `&str` against the window's current
///     worst and dropped without touching the allocator when it loses;
///   * only the page itself is turned into JSON. Building a `summary_json` for
///     every node in order to throw all but 200 of them away was ~1.3s of the
///     1.35s this used to take.
///
/// Sorted by `rel_path` so the flat list reads in tree order, which is what
/// makes it an inventory rather than a hash dump. Deep paging degrades back
/// towards the old cost, because the window is what is bounded — but "click
/// next a thousand times" is not how anyone finds a node. The filter is.
fn route_list(view: &View, q: &Query) -> Result<Response, Response> {
    use std::collections::BinaryHeap;

    let seg = view.segment()?;
    let needle = q.get("q").unwrap_or_default().to_lowercase();
    let offset = q.get_usize("offset").unwrap_or(0);
    let limit = q.get_usize("limit").unwrap_or(LIST_DEFAULT_LIMIT).clamp(1, LIST_MAX_LIMIT);
    let window = offset.saturating_add(limit);

    let mut total = 0u64;
    let mut matched = 0usize;
    // Max-heap: the element to evict is always the largest key in the window,
    // which is exactly what `peek` gives. Keyed on (rel_path, name) so the
    // order is total even if two nodes somehow share a path.
    let mut top: BinaryHeap<(String, String)> = BinaryHeap::new();

    seg.for_each_live(|rec| {
        total += 1;
        if !needle.is_empty()
            && !rec.name().to_lowercase().contains(&needle)
            && !rec.rel_path().to_lowercase().contains(&needle)
        {
            return;
        }
        matched += 1;
        let path = rec.rel_path();
        if top.len() < window {
            top.push((path.to_string(), rec.name().to_string()));
        } else if let Some(worst) = top.peek() {
            // Borrowed comparison first: a record that does not make the window
            // costs a string compare and no allocation at all.
            if (path, rec.name()) < (worst.0.as_str(), worst.1.as_str()) {
                top.pop();
                top.push((path.to_string(), rec.name().to_string()));
            }
        }
    });

    let mut window_sorted = top.into_sorted_vec();
    // `into_sorted_vec` is ascending, which is the order the page wants.
    let page: Vec<Value> = window_sorted
        .drain(..)
        .skip(offset)
        .filter_map(|(_, name)| match seg.lookup(&name) {
            Lookup::Found(rec) if !rec.is_tombstone() => Some(summary_json(&rec)),
            // The segment cannot change under a request (the mapping is pinned
            // for its lifetime), so this is unreachable rather than a race —
            // but skipping beats unwrapping in a route.
            _ => None,
        })
        .collect();

    Ok(json_response(
        200,
        &json!({
            "epoch": seg.epoch,
            "total": total,
            "matched": matched,
            "offset": offset,
            "limit": limit,
            "nodes": page,
        }),
    ))
}

// ---------------------------------------------------------------------------
// Sizes
// ---------------------------------------------------------------------------

/// What "big" means. The two answer different questions and routinely disagree:
/// a tree of many tiny translation documents is heavy in the segment (every
/// node pays a record header, a path, a child list) and light on disk, while one
/// un-imaged 2 MB document is the reverse.
#[derive(Clone, Copy, PartialEq)]
enum Metric {
    /// The documents' size ON DISK, summed per node. "How much content is
    /// under here."
    Docs,
    /// The node's whole footprint in the shared-memory arena: record header,
    /// name, path, father, children, inlinks, and every language's raw bytes
    /// and image. "What is filling /dev/shm" — the question `quantaDb.shmSize`
    /// is sized against, and the one nothing else in the tooling answers per
    /// subtree.
    Segment,
}

impl Metric {
    fn parse(s: &str) -> Metric {
        match s {
            "segment" => Metric::Segment,
            _ => Metric::Docs,
        }
    }

    fn label(self) -> &'static str {
        match self {
            Metric::Docs => "docs",
            Metric::Segment => "segment",
        }
    }

    fn of(self, rec: &RecordView<'_>) -> u64 {
        match self {
            Metric::Docs => rec.langs().iter().map(|l| l.doc_size.max(0) as u64).sum(),
            Metric::Segment => rec.record_bytes() as u64,
        }
    }
}

/// How many children of one level `/api/sizes` returns before aggregating the
/// tail into `rest`.
const SIZES_DEFAULT_LIMIT: usize = 200;
const SIZES_MAX_LIMIT: usize = 2000;

/// A father chain longer than this is taken as broken rather than followed.
///
/// The chain comes from the segment, and the segment is derived from a
/// filesystem walk — a cycle should be impossible. "Should be impossible" is
/// not a termination argument for a loop in a request handler.
const MAX_ANCESTRY: usize = 256;

/// The whole tree, flattened into parallel arrays for one request.
///
/// Index-based rather than name-keyed after the initial pass, because the
/// rollup chases father pointers once per node and a `HashMap` probe per hop
/// would dominate. Every string here borrows the segment mapping (see
/// `SegmentReader::for_each_live`), so building this over a 200k-node tree
/// allocates the vectors and nothing else.
struct Flat<'a> {
    name: Vec<&'a str>,
    father: Vec<Option<usize>>,
    own: Vec<u64>,
}

fn flatten<'a>(seg: &'a SegmentReader, metric: Metric) -> (Flat<'a>, std::collections::HashMap<&'a str, usize>) {
    use std::collections::HashMap;
    let mut name = Vec::new();
    let mut father_name: Vec<Option<&str>> = Vec::new();
    let mut own = Vec::new();
    let mut idx: HashMap<&str, usize> = HashMap::new();

    seg.for_each_live(|rec| {
        idx.insert(rec.name(), name.len());
        name.push(rec.name());
        father_name.push(rec.father());
        own.push(metric.of(rec));
    });

    // Resolve father names to indices once, so the rollup never hashes again.
    // A father that is not in the index (the node above was deleted, or never
    // indexed) makes this node a root of its own — which is exactly how the
    // tree view already presents it.
    let father = father_name
        .iter()
        .map(|f| f.and_then(|n| idx.get(n).copied()))
        .collect();

    (Flat { name, father, own }, idx)
}

/// Total the subtree under each of `root`'s direct children.
///
/// Rather than a full rollup of every node's subtree, this buckets: each node
/// walks up until it meets an ancestor that is a direct child of `root`, and
/// its bytes land in that bucket. One level is all a treemap draws, and the
/// walk is memoised, so the whole pass is near-linear and touches no hash map.
///
/// `root = None` means the forest — the buckets are the fatherless nodes.
fn bucket_totals(flat: &Flat<'_>, root: Option<usize>) -> (Vec<usize>, Vec<u64>, Vec<u64>) {
    let n = flat.name.len();
    const UNKNOWN: i64 = -2;
    const OUTSIDE: i64 = -1;

    let mut buckets: Vec<usize> = Vec::new();
    let mut bucket_of: Vec<i64> = vec![UNKNOWN; n];
    for i in 0..n {
        if flat.father[i] == root {
            bucket_of[i] = buckets.len() as i64;
            buckets.push(i);
        }
    }
    if let Some(r) = root {
        // The root itself is in nobody's bucket; its own bytes are reported
        // separately so "this folder's own documents" stays visible next to
        // what its children weigh.
        bucket_of[r] = OUTSIDE;
    }

    let mut total = vec![0u64; buckets.len()];
    let mut count = vec![0u64; buckets.len()];
    let mut pending: Vec<usize> = Vec::new();

    for i in 0..n {
        if bucket_of[i] == UNKNOWN {
            pending.clear();
            let mut cur = i;
            // Climb until something already resolved, or the top.
            let b = loop {
                if bucket_of[cur] != UNKNOWN {
                    break bucket_of[cur];
                }
                pending.push(cur);
                match flat.father[cur] {
                    Some(f) if pending.len() < MAX_ANCESTRY => cur = f,
                    _ => break OUTSIDE,
                }
            };
            // Path compression: every node on the way up shares the answer, so
            // a deep tree is still one pass overall.
            for &p in &pending {
                bucket_of[p] = b;
            }
        }
        let b = bucket_of[i];
        if b >= 0 {
            total[b as usize] += flat.own[i];
            count[b as usize] += 1;
        }
    }

    (buckets, total, count)
}

/// Subtree sizes for one level of the tree, heaviest first.
fn route_sizes(view: &View, q: &Query) -> Result<Response, Response> {
    let seg = view.segment()?;
    let metric = Metric::parse(&q.get("metric").unwrap_or_default());
    let name = q.get("name").filter(|n| !n.is_empty());

    let (flat, idx) = flatten(seg, metric);

    let root: Option<usize> = match &name {
        Some(n) => match idx.get(n.as_str()) {
            Some(i) => Some(*i),
            None => {
                return Err(json_response(404, &json!({ "error": "no such node", "name": n })))
            }
        },
        None => None,
    };

    let (buckets, totals, counts) = bucket_totals(&flat, root);

    // Heaviest first; ties by name so repeated loads do not reshuffle.
    let mut order: Vec<usize> = (0..buckets.len()).collect();
    order.sort_by(|&a, &b| {
        totals[b]
            .cmp(&totals[a])
            .then_with(|| flat.name[buckets[a]].cmp(flat.name[buckets[b]]))
    });

    // A level can have thousands of children (one translation node per string).
    // Sending every one of them is pointless: past the first couple of hundred
    // no map can draw them and no list is read that far. The tail still has to
    // be COUNTED, though, or the shares stop adding up — so it comes back
    // aggregated rather than dropped.
    let limit = q.get_usize("limit").unwrap_or(SIZES_DEFAULT_LIMIT).clamp(1, SIZES_MAX_LIMIT);
    let rest = json!({
        "count": order.len().saturating_sub(limit),
        "total": order.iter().skip(limit).map(|&o| totals[o]).sum::<u64>(),
        "nodes": order.iter().skip(limit).map(|&o| counts[o]).sum::<u64>(),
    });

    let children: Vec<Value> = order
        .iter()
        .take(limit)
        .map(|&o| {
            let i = buckets[o];
            let rel_path = match seg.lookup(flat.name[i]) {
                Lookup::Found(rec) => rec.rel_path().to_string(),
                _ => String::new(),
            };
            json!({
                "name": flat.name[i],
                "rel_path": rel_path,
                "total": totals[o],
                "own": flat.own[i],
                "nodes": counts[o],
            })
        })
        .collect();

    let own = root.map(|r| flat.own[r]).unwrap_or(0);
    let total: u64 = totals.iter().sum::<u64>() + own;
    let nodes: u64 = counts.iter().sum::<u64>() + u64::from(root.is_some());

    // Breadcrumb, root-ward. Built from the father chain rather than by
    // splitting rel_path: a node's name is the key, and rel_path segments are
    // directory names that a symlinked container can make disagree.
    let mut crumbs: Vec<Value> = Vec::new();
    let mut cur = root.and_then(|r| flat.father[r]);
    let mut hops = 0;
    while let Some(i) = cur {
        let rel_path = match seg.lookup(flat.name[i]) {
            Lookup::Found(rec) => rec.rel_path().to_string(),
            _ => String::new(),
        };
        crumbs.push(json!({ "name": flat.name[i], "rel_path": rel_path }));
        cur = flat.father[i];
        hops += 1;
        if hops >= MAX_ANCESTRY {
            break;
        }
    }
    crumbs.reverse();

    let rel_path = root
        .map(|r| match seg.lookup(flat.name[r]) {
            Lookup::Found(rec) => rec.rel_path().to_string(),
            _ => String::new(),
        })
        .unwrap_or_default();

    Ok(json_response(
        200,
        &json!({
            "epoch": seg.epoch,
            "metric": metric.label(),
            "name": name,
            "rel_path": rel_path,
            "total": total,
            "own": own,
            "nodes": nodes,
            "breadcrumb": crumbs,
            "children": children,
            "rest": rest,
        }),
    ))
}

/// Segment-wide totals for the browser's header, distinct from `/api/stats`:
/// this is what the *index* holds, that is how the *process* is doing.
fn route_summary(view: &View) -> Response {
    use std::sync::atomic::Ordering::Relaxed;
    let seg = match view.seg.as_ref() {
        Some(s) => s,
        None => {
            return json_response(
                200,
                &json!({
                    "segment": false,
                    "arena": view.arena_ok,
                    "epoch": view.snap.data_epoch,
                    "coherent": view.snap.watch_coherent == 1,
                }),
            )
        }
    };
    let h = seg.header();
    json_response(
        200,
        &json!({
            "segment": true,
            "arena": view.arena_ok,
            "epoch": seg.epoch,
            "coherent": view.snap.watch_coherent == 1,
            "daemon_pid": view.snap.daemon_pid,
            "nodes": h.node_count.load(Relaxed),
            "links": h.link_count.load(Relaxed),
            "doc_bytes": h.doc_bytes.load(Relaxed),
            "img_bytes": h.img_bytes.load(Relaxed),
            "raw_bytes": h.raw_bytes.load(Relaxed),
            "tombstones": h.tombstone_count.load(Relaxed),
            "seg_size": h.seg_size,
            "arena_used": h.arena_next.load(Relaxed),
            "dead_bytes": h.dead_bytes.load(Relaxed),
            "str_count": h.str_count.load(Relaxed),
            "str_bytes": h.str_bytes.load(Relaxed),
        }),
    )
}

// ---------------------------------------------------------------------------
// HTTP plumbing
// ---------------------------------------------------------------------------

pub struct Response {
    status: u16,
    content_type: &'static str,
    body: Vec<u8>,
    /// Extra headers, already formatted as `Name: value` lines.
    extra: Vec<String>,
}

fn json_response(status: u16, v: &Value) -> Response {
    Response {
        status,
        content_type: "application/json; charset=utf-8",
        body: serde_json::to_vec(v).unwrap_or_else(|_| b"{\"error\":\"encode failed\"}".to_vec()),
        extra: Vec::new(),
    }
}

fn text_response(status: u16, body: &str) -> Response {
    Response {
        status,
        content_type: "text/plain; charset=utf-8",
        body: body.as_bytes().to_vec(),
        extra: Vec::new(),
    }
}

fn asset(content_type: &'static str, body: &str) -> Response {
    Response {
        status: 200,
        content_type,
        body: body.as_bytes().to_vec(),
        extra: Vec::new(),
    }
}

fn bad_request(detail: &str) -> Response {
    json_response(400, &json!({ "error": "bad request", "detail": detail }))
}

fn reason(status: u16) -> &'static str {
    match status {
        200 => "OK",
        301 => "Moved Permanently",
        400 => "Bad Request",
        401 => "Unauthorized",
        403 => "Forbidden",
        404 => "Not Found",
        405 => "Method Not Allowed",
        429 => "Too Many Requests",
        503 => "Service Unavailable",
        _ => "Error",
    }
}

impl Response {
    fn with_header(mut self, h: String) -> Self {
        self.extra.push(h);
        self
    }

    /// Wire bytes. `Connection: close` for the same reason the exporter uses
    /// it: one request per connection, and a keep-alive we do not honour makes
    /// every client wait out its own timeout.
    fn encode(&self, head_only: bool) -> Vec<u8> {
        let mut head = format!(
            "HTTP/1.1 {} {}\r\nContent-Type: {}\r\nContent-Length: {}\r\nConnection: close\r\n\
             Cache-Control: no-store\r\nX-Content-Type-Options: nosniff\r\n\
             Referrer-Policy: no-referrer\r\n\
             Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; \
             connect-src 'self'; img-src 'self' data:; form-action 'none'; frame-ancestors 'none'\r\n",
            self.status,
            reason(self.status),
            self.content_type,
            self.body.len()
        );
        for h in &self.extra {
            head.push_str(h);
            head.push_str("\r\n");
        }
        head.push_str("\r\n");
        let mut out = head.into_bytes();
        if !head_only {
            out.extend_from_slice(&self.body);
        }
        out
    }
}

/// Parsed query string. Values are percent-decoded; repeated keys keep the
/// first, which is the conservative reading when a client sends both.
pub struct Query(Vec<(String, String)>);

impl Query {
    pub fn parse(s: &str) -> Query {
        let mut out = Vec::new();
        for pair in s.split('&').filter(|p| !p.is_empty()) {
            let (k, v) = match pair.split_once('=') {
                Some((k, v)) => (k, v),
                None => (pair, ""),
            };
            out.push((percent_decode(k), percent_decode(v)));
        }
        Query(out)
    }

    pub fn get(&self, key: &str) -> Option<String> {
        self.0.iter().find(|(k, _)| k == key).map(|(_, v)| v.clone())
    }

    fn get_usize(&self, key: &str) -> Option<usize> {
        self.get(key)?.parse().ok()
    }
}

/// `%XX` and `+`, decoded lossily.
///
/// Node names are arbitrary directory names, so the browser must be able to
/// send bytes that are not URL-safe. Invalid sequences are left verbatim rather
/// than rejected: the worst outcome is a name that matches nothing, and this
/// value never reaches the filesystem.
pub fn percent_decode(s: &str) -> String {
    let b = s.as_bytes();
    let mut out: Vec<u8> = Vec::with_capacity(b.len());
    let mut i = 0;
    while i < b.len() {
        match b[i] {
            b'+' => {
                out.push(b' ');
                i += 1;
            }
            // Indexed over bytes, not the `str`: a name can carry any byte a
            // directory can, so slicing the string here could land mid-codepoint
            // and panic on input the client fully controls.
            b'%' if i + 2 < b.len() => {
                match hex_pair(b[i + 1], b[i + 2]) {
                    Some(byte) => {
                        out.push(byte);
                        i += 3;
                    }
                    None => {
                        out.push(b'%');
                        i += 1;
                    }
                }
            }
            c => {
                out.push(c);
                i += 1;
            }
        }
    }
    String::from_utf8_lossy(&out).into_owned()
}

fn hex_pair(hi: u8, lo: u8) -> Option<u8> {
    Some((hex_digit(hi)? << 4) | hex_digit(lo)?)
}

fn hex_digit(c: u8) -> Option<u8> {
    match c {
        b'0'..=b'9' => Some(c - b'0'),
        b'a'..=b'f' => Some(c - b'a' + 10),
        b'A'..=b'F' => Some(c - b'A' + 10),
        _ => None,
    }
}

/// Length-independent comparison, so a wrong token cannot be narrowed down by
/// timing the reply. Length is compared first and leaks only the length.
fn secret_eq(a: &str, b: &str) -> bool {
    let (a, b) = (a.as_bytes(), b.as_bytes());
    if a.len() != b.len() {
        return false;
    }
    let mut diff = 0u8;
    for i in 0..a.len() {
        diff |= a[i] ^ b[i];
    }
    diff == 0
}

/// What the client presented, from either accepted place.
///
/// The header is the right way; `?token=` exists because a dashboard is opened
/// by pasting a URL into a browser, where setting a header is not an option.
fn presented_token(auth: Option<&str>, q: &Query) -> Option<String> {
    if let Some(a) = auth {
        let a = a.trim();
        if let Some(rest) = a.strip_prefix("Bearer ").or_else(|| a.strip_prefix("bearer ")) {
            return Some(rest.trim().to_string());
        }
        return Some(a.to_string());
    }
    q.get("token")
}

/// Where a request path falls relative to the configured base.
enum Base<'a> {
    /// Inside the prefix; the path with it removed, always starting with `/`.
    Under(&'a str),
    /// Exactly the prefix, with no trailing slash.
    Bare,
    /// Not under the prefix at all — including `/qdbx`, which shares a textual
    /// prefix with `/qdb` but is a different path.
    Outside,
}

fn strip_base<'a>(base: &str, path: &'a str) -> Base<'a> {
    if base.is_empty() {
        return Base::Under(path);
    }
    if path == base {
        return Base::Bare;
    }
    match path.strip_prefix(base) {
        Some(rest) if rest.starts_with('/') => Base::Under(rest),
        _ => Base::Outside,
    }
}

/// Route one parsed request. Split out from the connection loop so the whole
/// surface is testable without a socket.
pub fn dispatch(
    cfg: &Config,
    stats: StatsFn,
    method: &str,
    target: &str,
    auth: Option<&str>,
) -> Response {
    let (raw_path, raw_query) = match target.split_once('?') {
        Some((p, q)) => (p, q),
        None => (target, ""),
    };
    let query = Query::parse(raw_query);

    if !matches!(method, "GET" | "HEAD") {
        return text_response(405, "method not allowed\n").with_header("Allow: GET, HEAD".into());
    }

    // Everything below routes on the path WITHOUT the prefix, so the route
    // table is the same whether this is mounted at the root or under one.
    let path = match strip_base(&cfg.base_path, raw_path) {
        Base::Under(rest) => rest,
        // The prefix with no trailing slash. The page's own asset and API
        // requests are relative, and a browser resolves those against the
        // directory of the current URL -- at `/qdb` that directory is `/`, so
        // every one of them would leave the prefix. Redirect rather than serve
        // a page that cannot load itself.
        //
        // 301 is the convention for adding a missing trailing slash, and it is
        // safe here despite being config-derived: every response carries
        // `Cache-Control: no-store`, so a base-path change is not stuck in
        // somebody's browser.
        Base::Bare => {
            let loc = if raw_query.is_empty() {
                format!("{}/", cfg.base_path)
            } else {
                format!("{}/?{}", cfg.base_path, raw_query)
            };
            return text_response(301, "").with_header(format!("Location: {loc}"));
        }
        Base::Outside => {
            return json_response(404, &json!({ "error": "not found", "path": raw_path }))
        }
    };

    // Liveness is exempt: a probe must not need the secret, and it reveals only
    // that the process is up.
    if path == "/healthz" {
        return text_response(200, "ok\n");
    }

    if let Some(want) = &cfg.token {
        let ok = presented_token(auth, &query).is_some_and(|got| secret_eq(&got, want));
        if !ok {
            return json_response(401, &json!({ "error": "unauthorized" }))
                .with_header("WWW-Authenticate: Bearer".into());
        }
    }

    match path {
        "/" | "/index.html" => asset("text/html; charset=utf-8", INDEX_HTML),
        "/app.css" => asset("text/css; charset=utf-8", APP_CSS),
        "/app.js" => asset("text/javascript; charset=utf-8", APP_JS),
        "/api/stats" => Response {
            status: 200,
            content_type: "application/json; charset=utf-8",
            body: stats(&cfg.shm_path, &cfg.data_dir).into_bytes(),
            extra: Vec::new(),
        },
        "/api/summary" => route_summary(&View::open(cfg)),
        "/api/roots" => unwrap_route(route_roots(&View::open(cfg))),
        "/api/node" => unwrap_route(route_node(&View::open(cfg), &query)),
        "/api/doc" => unwrap_route(route_doc(&View::open(cfg), &query)),
        "/api/list" => unwrap_route(route_list(&View::open(cfg), &query)),
        "/api/sizes" => unwrap_route(route_sizes(&View::open(cfg), &query)),
        _ => json_response(404, &json!({ "error": "not found", "path": path })),
    }
}

fn unwrap_route(r: Result<Response, Response>) -> Response {
    match r {
        Ok(v) => v,
        Err(e) => e,
    }
}

/// Read the request line and the one header we care about, discarding the rest.
///
/// Returns `None` when the client sent nothing usable — a port scan, a TLS
/// ClientHello, a dropped connection — none of which deserves a reply.
fn read_request(stream: &TcpStream) -> Option<(String, String, Option<String>)> {
    let mut r = BufReader::new(stream);
    let mut line = String::new();
    let mut take = (&mut r).take(MAX_LINE as u64);
    take.read_line(&mut line).ok()?;
    let mut parts = line.split_whitespace();
    let method = parts.next()?.to_string();
    let target = parts.next()?.to_string();

    let mut auth = None;
    for _ in 0..MAX_HEADERS {
        let mut h = String::new();
        let mut take = (&mut r).take(MAX_LINE as u64);
        if take.read_line(&mut h).ok()? == 0 {
            break;
        }
        let t = h.trim_end();
        if t.is_empty() {
            break;
        }
        if let Some((k, v)) = t.split_once(':') {
            if k.eq_ignore_ascii_case("authorization") {
                auth = Some(v.trim().to_string());
            }
        }
    }
    Some((method, target, auth))
}

/// Serve until killed.
///
/// Thread per connection with a hard cap: a browser opens several connections
/// for one page, so serialising them (as the exporter's loop does, correctly,
/// for a scraper) would make every page load wait on its own stylesheet. The
/// cap is what keeps "thread per connection" from being an unbounded spawner
/// when something decides to hold sockets open.
pub fn serve(cfg: Config, stats: StatsFn) -> Result<(), String> {
    let listener = TcpListener::bind(&cfg.addr)
        .map_err(|e| format!("cannot listen on {}: {e}", cfg.addr))?;
    eprintln!(
        "qdbstat: serving the qdb dashboard on http://{}{}/",
        cfg.addr, cfg.base_path
    );
    if cfg.token.is_none() {
        eprintln!(
            "qdbstat: dashboard is UNAUTHENTICATED (it serves node paths and document \
             content); set QUANTA_DB_DASHBOARD_TOKEN to require a shared secret"
        );
    }

    let cfg = Arc::new(cfg);
    let inflight = Arc::new(AtomicUsize::new(0));

    for stream in listener.incoming() {
        let mut stream = match stream {
            Ok(s) => s,
            // Per-connection, exactly as the exporter treats it: the peer went
            // away or descriptors are exhausted, neither of which is a reason
            // to stop serving.
            Err(e) => {
                eprintln!("qdbstat: dashboard accept failed: {e}");
                continue;
            }
        };
        let _ = stream.set_read_timeout(Some(IO_TIMEOUT));
        let _ = stream.set_write_timeout(Some(IO_TIMEOUT));

        if inflight.load(Ordering::Relaxed) >= MAX_INFLIGHT {
            let body = text_response(429, "too many concurrent requests\n").encode(false);
            let _ = stream.write_all(&body);
            continue;
        }
        inflight.fetch_add(1, Ordering::Relaxed);
        let cfg = Arc::clone(&cfg);
        let inflight2 = Arc::clone(&inflight);
        let spawned = std::thread::Builder::new()
            .name("qdb-dash".into())
            .spawn(move || {
                if let Some((method, target, auth)) = read_request(&stream) {
                    let resp = dispatch(&cfg, stats, &method, &target, auth.as_deref());
                    let _ = stream.write_all(&resp.encode(method == "HEAD"));
                    let _ = stream.flush();
                }
                inflight2.fetch_sub(1, Ordering::Relaxed);
            });
        if spawned.is_err() {
            inflight.fetch_sub(1, Ordering::Relaxed);
        }
    }
    Ok(())
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

#[cfg(test)]
mod tests {
    use super::*;
    use crate::shm::{encode_record, seg_backed_for, slot_count_for, LangDoc, RecordInput, SegmentWriter};

    /// The dashboard is a projection of a segment, so every test builds a real
    /// one rather than a mock: the interesting failures (an imaged document
    /// that will not decode, a child that is not in the index) only exist at
    /// that layer.
    struct Fixture {
        dir: PathBuf,
        cfg: Config,
    }

    impl Drop for Fixture {
        fn drop(&mut self) {
            let _ = std::fs::remove_dir_all(&self.dir);
        }
    }

    fn stub_stats(_shm: &Path, _data: &Path) -> String {
        r#"{"health":"healthy"}"#.to_string()
    }

    /// A three-node tree: `home` (root) with children `about` (a plain node
    /// whose document is stored raw) and `blog` (a symlinked container whose
    /// document is imaged), plus `ghost` listed as a child of `home` but never
    /// indexed.
    fn fixture(tag: &str, token: Option<&str>) -> Fixture {
        let dir = std::env::temp_dir().join(format!("qdb_dash_{tag}_{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();

        let slots = slot_count_for(8);
        let mut w =
            SegmentWriter::create(&dir, 7, 1 << 20, seg_backed_for(0, slots), slots, 0).unwrap();

        let no_children: Vec<(String, bool)> = Vec::new();
        let no_inlinks: Vec<String> = Vec::new();

        // home — the root, listing a plain child, a symlink whose filename does
        // happen to be a node name, a symlink whose filename is not (the normal
        // case: link rows are keyed by target, not by the symlink), and a child
        // directory the daemon never indexed.
        let home_children = vec![
            ("about".to_string(), false),
            ("blog".to_string(), true),
            ("featured".to_string(), true),
            ("ghost".to_string(), false),
        ];
        let home_doc = br#"{"title":"Home","nav":[1,2]}"#;
        let home_langs = vec![LangDoc {
            lang: "",
            doc: home_doc,
            corrupt: false,
            doc_mtime: 100,
            doc_size: home_doc.len() as i64,
            image: &[],
        }];
        let home = encode_record(
            &RecordInput {
                name: "home",
                rel_path: "home",
                father: None,
                generation: 1,
                mtime: 100,
                children: &home_children,
                inlinks: &no_inlinks,
                langs: &home_langs,
            },
            false,
        );
        w.upsert("home", &home).unwrap();

        // about — raw in the segment, and a second language so the UI has tabs
        // to render.
        let about_en = br#"{"title":"About"}"#;
        let about_it = br#"{"title":"Chi siamo"}"#;
        let about_langs = vec![
            LangDoc {
                lang: "",
                doc: about_en,
                corrupt: false,
                doc_mtime: 200,
                doc_size: about_en.len() as i64,
                image: &[],
            },
            LangDoc {
                lang: "it",
                doc: about_it,
                corrupt: false,
                doc_mtime: 201,
                doc_size: about_it.len() as i64,
                image: &[],
            },
        ];
        let about = encode_record(
            &RecordInput {
                name: "about",
                rel_path: "home/about",
                father: Some("home"),
                generation: 2,
                mtime: 200,
                children: &no_children,
                inlinks: &no_inlinks,
                langs: &about_langs,
            },
            false,
        );
        w.upsert("about", &about).unwrap();

        // blog — imaged, with no raw bytes at all, which is what the daemon
        // actually publishes for a document it could image.
        let value = serde_json::json!({ "title": "Blog", "tags": ["a", "b"], "n": 3 });
        let mut interner = image::Interner::new();
        let ids = image::encode(&value, &mut interner);
        assert!(!ids.is_empty(), "the fixture document must be imageable");
        let resolved = image::resolve(&ids, |id| {
            let s = interner.get(id)?;
            w.place_string(id, s.as_bytes()).ok()
        })
        .expect("the fixture image must resolve into the segment");
        let blog_inlinks = vec!["home".to_string()];
        let blog_langs = vec![LangDoc {
            lang: "",
            doc: b"",
            corrupt: false,
            doc_mtime: 300,
            doc_size: 42,
            image: &resolved,
        }];
        let blog = encode_record(
            &RecordInput {
                name: "blog",
                rel_path: "home/blog",
                father: Some("home"),
                generation: 3,
                mtime: 300,
                children: &no_children,
                inlinks: &blog_inlinks,
                langs: &blog_langs,
            },
            false,
        );
        w.upsert("blog", &blog).unwrap();

        w.set_counts(3, 1, 200, resolved.len() as u64, 0);
        w.publish_ready();

        Fixture {
            cfg: Config {
                addr: "127.0.0.1:0".into(),
                // Deliberately absent: the arena is optional, and a dashboard
                // that only worked with metrics on would be useless in exactly
                // the situation someone opens it.
                shm_path: dir.join("metrics.shm"),
                data_dir: dir.clone(),
                token: token.map(str::to_string),
                base_path: String::new(),
            },
            dir,
        }
    }

    fn get(f: &Fixture, target: &str) -> (u16, Value) {
        let r = dispatch(&f.cfg, stub_stats, "GET", target, None);
        let v = serde_json::from_slice(&r.body).unwrap_or(Value::Null);
        (r.status, v)
    }

    #[test]
    fn the_segment_is_found_without_an_arena() {
        // The arena names the active epoch, but quanta_db.metrics=off leaves
        // none, and that must not read as "there is no index".
        let f = fixture("noarena", None);
        let (status, v) = get(&f, "/api/summary");
        assert_eq!(status, 200);
        assert_eq!(v["segment"], Value::Bool(true), "the directory scan must find epoch 7");
        assert_eq!(v["arena"], Value::Bool(false));
        assert_eq!(v["epoch"], 7);
        assert_eq!(v["nodes"], 3);
    }

    #[test]
    fn a_half_written_newer_epoch_does_not_blank_the_view() {
        // A compaction creates epoch N+1 and fills it before publishing
        // `ready`. With no arena to say which epoch is live, taking the highest
        // file on disk would serve nothing for the length of every compaction.
        let f = fixture("compacting", None);
        let slots = slot_count_for(1);
        // Created and never published: `ready` stays 0, so no reader may use it.
        let _half =
            SegmentWriter::create(&f.dir, 8, 1 << 20, seg_backed_for(0, slots), slots, 0).unwrap();

        let (status, v) = get(&f, "/api/summary");
        assert_eq!(status, 200);
        assert_eq!(v["epoch"], 7, "the last READY epoch is still served");
        assert_eq!(v["nodes"], 3);
    }

    #[test]
    fn roots_are_the_fatherless_nodes() {
        let f = fixture("roots", None);
        let (status, v) = get(&f, "/api/roots");
        assert_eq!(status, 200);
        assert_eq!(v["total"], 3, "every live record is counted");
        let roots = v["roots"].as_array().unwrap();
        assert_eq!(roots.len(), 1);
        assert_eq!(roots[0]["name"], "home");
        assert_eq!(roots[0]["children"], 4);
        assert_eq!(roots[0]["child_links"], 2, "the symlinked children are separable");
    }

    #[test]
    fn a_node_resolves_its_children_and_flags_the_unindexed_one() {
        // Drift between what a node claims as children and what the index holds
        // is the reason to open this dashboard, so it has to be visible rather
        // than filtered out.
        let f = fixture("children", None);
        let (status, v) = get(&f, "/api/node?name=home");
        assert_eq!(status, 200);
        assert_eq!(v["rel_path"], "home");
        assert_eq!(v["father"], Value::Null);
        let kids = v["children"].as_array().unwrap();
        assert_eq!(kids.len(), 4);
        let ghost = kids.iter().find(|c| c["name"] == "ghost").unwrap();
        assert_eq!(ghost["kind"], "missing", "a child dir the daemon never indexed is drift");
        let blog = kids.iter().find(|c| c["name"] == "blog").unwrap();
        assert_eq!(blog["kind"], "node");
        assert_eq!(blog["link"], Value::Bool(true));
        assert_eq!(blog["rel_path"], "home/blog");
    }

    #[test]
    fn a_symlink_is_not_reported_as_missing() {
        // A link row is (container, TARGET), so a symlink's own filename is
        // never a node name -- looking it up finds nothing. Calling that
        // "missing" would mark every link in the tree as broken, which is the
        // bug this distinguishes against.
        let f = fixture("symlink", None);
        let (_, v) = get(&f, "/api/node?name=home");
        let kids = v["children"].as_array().unwrap();
        let featured = kids.iter().find(|c| c["name"] == "featured").unwrap();
        assert_eq!(featured["kind"], "symlink");
        assert_eq!(featured["link"], Value::Bool(true));
    }

    #[test]
    fn the_link_graph_is_navigable_in_both_directions() {
        // The segment records an edge only on the target, so "what links here"
        // is free and "what does this link to" has to be scanned for. Both have
        // to be answerable or a symlink row in the tree is a dead end.
        let f = fixture("links", None);
        let (_, home) = get(&f, "/api/node?name=home");
        let out: Vec<&str> = home["links_out"]
            .as_array()
            .unwrap()
            .iter()
            .map(|n| n["name"].as_str().unwrap())
            .collect();
        assert_eq!(out, vec!["blog"], "home links to blog");

        let (_, blog) = get(&f, "/api/node?name=blog");
        let inl: Vec<&str> = blog["inlinks"]
            .as_array()
            .unwrap()
            .iter()
            .map(|n| n["name"].as_str().unwrap())
            .collect();
        assert_eq!(inl, vec!["home"], "and blog knows it is linked from home");
        assert_eq!(blog["links_out"].as_array().unwrap().len(), 0);
    }

    #[test]
    fn languages_are_reported_as_the_files_they_came_from() {
        // The neutral document is data.json and a translation is
        // data_<lang>.json; the whole point of the browser is to show files, so
        // the name has to be the one on disk.
        let f = fixture("files", None);
        let (_, v) = get(&f, "/api/node?name=about");
        let langs = v["langs"].as_array().unwrap();
        let files: Vec<&str> = langs.iter().map(|l| l["file"].as_str().unwrap()).collect();
        assert_eq!(files, vec!["data.json", "data_it.json"]);
        assert_eq!(langs[0]["raw"], Value::Bool(true));
        assert_eq!(langs[0]["imaged"], Value::Bool(false));
    }

    #[test]
    fn a_raw_document_is_served_from_the_segment() {
        let f = fixture("raw", None);
        let (status, v) = get(&f, "/api/doc?name=about&lang=it");
        assert_eq!(status, 200);
        assert_eq!(v["source"], "raw");
        assert_eq!(v["file"], "data_it.json");
        assert_eq!(v["doc"]["title"], "Chi siamo");
    }

    #[test]
    fn an_imaged_document_is_decoded_back_into_json() {
        // The common case in production: the daemon drops the raw bytes once a
        // document is imaged, so the image is the only in-segment copy and the
        // dashboard has to walk it rather than read the file.
        let f = fixture("imaged", None);
        let (status, v) = get(&f, "/api/doc?name=blog&lang=");
        assert_eq!(status, 200);
        assert_eq!(v["source"], "image");
        assert_eq!(v["doc"]["title"], "Blog");
        assert_eq!(v["doc"]["n"], 3);
        assert_eq!(v["doc"]["tags"][1], "b");
    }

    #[test]
    fn a_missing_node_or_language_is_a_404_not_an_error() {
        let f = fixture("missing", None);
        assert_eq!(get(&f, "/api/node?name=nope").0, 404);
        assert_eq!(get(&f, "/api/doc?name=about&lang=de").0, 404);
        // A child that is listed but not indexed is absent in exactly the same
        // way -- the tree links to it, and clicking must not 500.
        assert_eq!(get(&f, "/api/node?name=ghost").0, 404);
    }

    #[test]
    fn a_missing_name_is_a_client_error() {
        let f = fixture("noname", None);
        assert_eq!(get(&f, "/api/node").0, 400);
        assert_eq!(get(&f, "/api/doc").0, 400);
    }

    #[test]
    fn the_list_filters_and_pages_in_path_order() {
        let f = fixture("list", None);
        let (status, v) = get(&f, "/api/list");
        assert_eq!(status, 200);
        assert_eq!(v["matched"], 3);
        let paths: Vec<&str> = v["nodes"]
            .as_array()
            .unwrap()
            .iter()
            .map(|n| n["rel_path"].as_str().unwrap())
            .collect();
        assert_eq!(paths, vec!["home", "home/about", "home/blog"], "sorted by path");

        let (_, v) = get(&f, "/api/list?q=BLO");
        assert_eq!(v["matched"], 1, "the filter is case-insensitive");
        assert_eq!(v["total"], 3, "and still reports the whole index");
        assert_eq!(v["nodes"][0]["name"], "blog");

        let (_, v) = get(&f, "/api/list?offset=1&limit=1");
        assert_eq!(v["nodes"].as_array().unwrap().len(), 1);
        assert_eq!(v["nodes"][0]["rel_path"], "home/about");
    }

    /// A wider tree than `fixture`, for the paging window. Names are
    /// deliberately NOT in path order (node-9 sorts before node-10) so a test
    /// that pages through cannot pass by accident on insertion order.
    fn many_nodes(tag: &str, n: usize) -> Fixture {
        let dir = std::env::temp_dir().join(format!("qdb_dash_{tag}_{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();
        let slots = slot_count_for(n as u64);
        let mut w =
            SegmentWriter::create(&dir, 3, 8 << 20, seg_backed_for(0, slots), slots, 0).unwrap();
        for i in 0..n {
            let name = format!("node-{i}");
            let rel = format!("home/node-{i}");
            let doc = format!("{{\"i\":{i}}}");
            let langs = vec![LangDoc {
                lang: "",
                doc: doc.as_bytes(),
                corrupt: false,
                doc_mtime: i as i64,
                doc_size: doc.len() as i64,
                image: &[],
            }];
            let rec = encode_record(
                &RecordInput {
                    name: &name,
                    rel_path: &rel,
                    father: Some("home"),
                    generation: i as u64,
                    mtime: 0,
                    children: &[],
                    inlinks: &[],
                    langs: &langs,
                },
                false,
            );
            w.upsert(&name, &rec).unwrap();
        }
        w.set_counts(n as u64, 0, 0, 0, 0);
        w.publish_ready();
        Fixture {
            cfg: Config {
                addr: "127.0.0.1:0".into(),
                shm_path: dir.join("metrics.shm"),
                data_dir: dir.clone(),
                token: None,
                base_path: String::new(),
            },
            dir,
        }
    }

    #[test]
    fn paging_walks_the_whole_index_in_order_without_gaps() {
        // The list keeps only `offset + limit` keys while it scans, so every
        // page past the first exercises the eviction path -- the one place a
        // bounded window can silently drop or repeat a node. Walking the whole
        // tree a page at a time and comparing against the sort done the obvious
        // way is what catches that.
        const N: usize = 250;
        let f = many_nodes("paging", N);

        let mut expected: Vec<String> = (0..N).map(|i| format!("home/node-{i}")).collect();
        expected.sort();

        let mut seen: Vec<String> = Vec::new();
        let mut offset = 0;
        while offset < N {
            let (status, v) = get(&f, &format!("/api/list?offset={offset}&limit=7"));
            assert_eq!(status, 200);
            assert_eq!(v["matched"], N, "every page reports the full match count");
            assert_eq!(v["total"], N);
            for n in v["nodes"].as_array().unwrap() {
                seen.push(n["rel_path"].as_str().unwrap().to_string());
            }
            offset += 7;
        }
        assert_eq!(seen, expected, "the pages concatenate into the whole index, in order");

        // A window that lands past the end is empty, not an error and not a
        // wrapped-around page.
        let (status, v) = get(&f, "/api/list?offset=1000&limit=10");
        assert_eq!(status, 200);
        assert_eq!(v["nodes"].as_array().unwrap().len(), 0);
        assert_eq!(v["matched"], N);
    }

    #[test]
    fn a_filtered_page_is_ordered_across_the_whole_match_set() {
        // Filtering happens during the scan, so the window holds only matches:
        // page two of a filter must be the filter's second page, not the
        // index's.
        let f = many_nodes("filterpage", 250);
        let (_, v) = get(&f, "/api/list?q=node-1&limit=3");
        let first: Vec<&str> = v["nodes"].as_array().unwrap().iter()
            .map(|n| n["rel_path"].as_str().unwrap()).collect();
        assert_eq!(first, vec!["home/node-1", "home/node-10", "home/node-100"]);

        let (_, v) = get(&f, "/api/list?q=node-1&offset=3&limit=3");
        let second: Vec<&str> = v["nodes"].as_array().unwrap().iter()
            .map(|n| n["rel_path"].as_str().unwrap()).collect();
        assert_eq!(second, vec!["home/node-101", "home/node-102", "home/node-103"]);
    }

    #[test]
    fn there_is_no_segment_answer_is_a_503_that_explains_itself() {
        // An operator who opens this during an outage gets the one thing the
        // browser cannot show them otherwise: why it is empty.
        let dir = std::env::temp_dir().join(format!("qdb_dash_empty_{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();
        let f = Fixture {
            cfg: Config {
                addr: "127.0.0.1:0".into(),
                shm_path: dir.join("metrics.shm"),
                data_dir: dir.clone(),
                token: None,
                base_path: String::new(),
            },
            dir,
        };
        let (status, v) = get(&f, "/api/list");
        assert_eq!(status, 503);
        assert_eq!(v["error"], "no segment");
        assert!(v["detail"].as_str().unwrap().contains("daemon"));
        // The summary still answers, because the header has to render something
        // in exactly this case.
        let (status, v) = get(&f, "/api/summary");
        assert_eq!(status, 200);
        assert_eq!(v["segment"], Value::Bool(false));
    }

    #[test]
    fn the_token_gates_every_route_but_liveness() {
        let f = fixture("token", Some("s3cret"));
        assert_eq!(dispatch(&f.cfg, stub_stats, "GET", "/api/roots", None).status, 401);
        assert_eq!(dispatch(&f.cfg, stub_stats, "GET", "/", None).status, 401);
        // A probe must not need the secret.
        assert_eq!(dispatch(&f.cfg, stub_stats, "GET", "/healthz", None).status, 200);

        // Both ways of presenting it are accepted; the query string exists
        // because a dashboard is opened by pasting a URL.
        assert_eq!(
            dispatch(&f.cfg, stub_stats, "GET", "/api/roots", Some("Bearer s3cret")).status,
            200
        );
        assert_eq!(get(&f, "/api/roots?token=s3cret").0, 200);
        assert_eq!(get(&f, "/api/roots?token=wrong").0, 401);
        assert_eq!(
            dispatch(&f.cfg, stub_stats, "GET", "/api/roots", Some("Bearer s3cre")).status,
            401,
            "a prefix of the secret is not the secret"
        );
    }

    #[test]
    fn without_a_token_nothing_is_gated() {
        let f = fixture("open", None);
        assert_eq!(get(&f, "/api/roots").0, 200);
        assert_eq!(dispatch(&f.cfg, stub_stats, "GET", "/", None).status, 200);
    }

    #[test]
    fn only_reads_are_routed() {
        let f = fixture("methods", None);
        for m in ["POST", "PUT", "DELETE", "PATCH"] {
            let r = dispatch(&f.cfg, stub_stats, m, "/api/node?name=home", None);
            assert_eq!(r.status, 405, "{m} must not be routed");
        }
        // HEAD is a GET whose body is dropped at encode time, not a separate
        // route: a HEAD that 404'd where GET succeeds would be a lie.
        let r = dispatch(&f.cfg, stub_stats, "HEAD", "/api/node?name=home", None);
        assert_eq!(r.status, 200);
        assert!(!r.encode(true).ends_with(&r.body), "HEAD carries no body");
    }

    #[test]
    fn the_ui_is_served_from_the_binary() {
        let f = fixture("assets", None);
        for (path, needle) in [("/", "app.js"), ("/app.css", "--accent"), ("/app.js", "api/node")] {
            let r = dispatch(&f.cfg, stub_stats, "GET", path, None);
            assert_eq!(r.status, 200, "{path}");
            let body = String::from_utf8(r.body.clone()).unwrap();
            assert!(body.contains(needle), "{path} must contain {needle}");
        }
        // Nothing is fetched from a CDN: the container has no egress, and a
        // dashboard that renders blank there is worse than none.
        let js = String::from_utf8(dispatch(&f.cfg, stub_stats, "GET", "/app.js", None).body).unwrap();
        let html = String::from_utf8(dispatch(&f.cfg, stub_stats, "GET", "/", None).body).unwrap();
        for body in [&js, &html] {
            assert!(!body.contains("https://"), "assets must not reference the network");
        }
    }

    #[test]
    fn stats_are_handed_through_untouched() {
        let f = fixture("stats", None);
        let (status, v) = get(&f, "/api/stats");
        assert_eq!(status, 200);
        assert_eq!(v["health"], "healthy");
    }

    #[test]
    fn an_unknown_path_is_a_404() {
        let f = fixture("unknown", None);
        assert_eq!(get(&f, "/api/nope").0, 404);
        assert_eq!(get(&f, "/../etc/passwd").0, 404);
    }

    #[test]
    fn sizes_roll_a_subtree_up_into_its_children() {
        // home holds `about` (two documents) and `blog` (one, imaged). The
        // level's total is its children plus home's own document, and each
        // child carries everything beneath it.
        let f = fixture("sizes", None);
        let (status, v) = get(&f, "/api/sizes?name=home");
        assert_eq!(status, 200);
        assert_eq!(v["metric"], "docs");
        assert_eq!(v["own"], 28, "home's own data.json");

        let kids = v["children"].as_array().unwrap();
        let by: std::collections::HashMap<&str, &Value> =
            kids.iter().map(|c| (c["name"].as_str().unwrap(), c)).collect();
        // about = 17 + 21 across its two languages; blog = the 42 its imaged
        // document reports on disk.
        assert_eq!(by["about"]["total"], 38);
        assert_eq!(by["blog"]["total"], 42);
        assert_eq!(v["total"], 28 + 38 + 42, "own plus every child's subtree");
        assert_eq!(v["nodes"], 3);

        // Heaviest first, so the treemap and the list agree without sorting
        // twice in two languages.
        assert_eq!(kids[0]["name"], "blog");
    }

    #[test]
    fn the_forest_is_the_top_level() {
        // With no name, the buckets are the fatherless nodes -- the same set
        // the tree view opens on.
        let f = fixture("sizesroot", None);
        let (status, v) = get(&f, "/api/sizes");
        assert_eq!(status, 200);
        assert_eq!(v["name"], Value::Null);
        assert_eq!(v["own"], 0, "the forest has no documents of its own");
        let kids = v["children"].as_array().unwrap();
        assert_eq!(kids.len(), 1);
        assert_eq!(kids[0]["name"], "home");
        assert_eq!(kids[0]["total"], 28 + 38 + 42, "the whole tree rolls into its root");
        assert_eq!(v["nodes"], 3);
    }

    #[test]
    fn the_segment_metric_counts_what_shm_holds_not_what_disk_does() {
        // The two disagree by design: `blog`'s document is imaged, so on disk
        // it is 42 bytes while the segment carries a record header, a path, a
        // child list and the image. Reporting one as the other is how an
        // operator sizes /dev/shm wrong.
        let f = fixture("sizesseg", None);
        let (_, docs) = get(&f, "/api/sizes?name=home&metric=docs");
        let (status, seg) = get(&f, "/api/sizes?name=home&metric=segment");
        assert_eq!(status, 200);
        assert_eq!(seg["metric"], "segment");
        assert!(
            seg["total"].as_u64().unwrap() > docs["total"].as_u64().unwrap(),
            "records cost more than the documents they carry: {} vs {}",
            seg["total"], docs["total"]
        );
        // Every node pays a record, so the node count is the same either way.
        assert_eq!(seg["nodes"], docs["nodes"]);
    }

    #[test]
    fn a_size_breadcrumb_climbs_by_father_not_by_path() {
        // rel_path segments are directory names, and a symlinked container
        // makes them disagree with the father chain. The chain is the key.
        let f = fixture("sizescrumbs", None);
        let (_, v) = get(&f, "/api/sizes?name=about");
        let crumbs: Vec<&str> = v["breadcrumb"].as_array().unwrap().iter()
            .map(|c| c["name"].as_str().unwrap()).collect();
        assert_eq!(crumbs, vec!["home"]);
        assert_eq!(v["rel_path"], "home/about");

        let (_, v) = get(&f, "/api/sizes?name=home");
        assert_eq!(v["breadcrumb"].as_array().unwrap().len(), 0, "a root has no crumbs");
    }

    #[test]
    fn the_aggregated_tail_still_adds_up() {
        // A level with thousands of children returns only the head, so the tail
        // has to come back summed rather than dropped: the moment the parts
        // stop reconciling with the total, every percentage on the page is a
        // lie and the treemap's areas are wrong.
        const N: usize = 60;
        let dir = std::env::temp_dir().join(format!("qdb_dash_tail_{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();
        let slots = slot_count_for(N as u64 + 1);
        let mut w =
            SegmentWriter::create(&dir, 5, 8 << 20, seg_backed_for(0, slots), slots, 0).unwrap();

        let mk = |name: &str, rel: &str, father: Option<&str>, size: i64| {
            let langs = vec![LangDoc {
                lang: "",
                doc: b"x",
                corrupt: false,
                doc_mtime: 0,
                doc_size: size,
                image: &[],
            }];
            encode_record(
                &RecordInput {
                    name, rel_path: rel, father, generation: 1, mtime: 0,
                    children: &[], inlinks: &[], langs: &langs,
                },
                false,
            )
        };
        w.upsert("top", &mk("top", "top", None, 3)).unwrap();
        // Deliberately uneven, so head and tail are not interchangeable.
        for i in 0..N {
            let name = format!("c{i:03}");
            let rel = format!("top/{name}");
            w.upsert(&name, &mk(&name, &rel, Some("top"), (i as i64 + 1) * 10)).unwrap();
        }
        w.set_counts(N as u64 + 1, 0, 0, 0, 0);
        w.publish_ready();

        let f = Fixture {
            cfg: Config {
                addr: "127.0.0.1:0".into(),
                shm_path: dir.join("metrics.shm"),
                data_dir: dir.clone(),
                token: None,
                base_path: String::new(),
            },
            dir,
        };

        let (_, all) = get(&f, "/api/sizes?name=top&limit=2000");
        let full_total = all["total"].as_u64().unwrap();
        assert_eq!(all["children"].as_array().unwrap().len(), N);
        assert_eq!(all["rest"]["count"], 0);

        for limit in [1usize, 7, 59, 60, 61] {
            let (status, v) = get(&f, &format!("/api/sizes?name=top&limit={limit}"));
            assert_eq!(status, 200);
            let kids = v["children"].as_array().unwrap();
            assert_eq!(kids.len(), limit.min(N), "limit {limit}");
            assert_eq!(v["total"], full_total, "the total never depends on the limit");

            let head: u64 = kids.iter().map(|c| c["total"].as_u64().unwrap()).sum();
            let head_nodes: u64 = kids.iter().map(|c| c["nodes"].as_u64().unwrap()).sum();
            assert_eq!(
                head + v["rest"]["total"].as_u64().unwrap() + v["own"].as_u64().unwrap(),
                full_total,
                "head + tail + own must reconcile at limit {limit}"
            );
            assert_eq!(
                head_nodes + v["rest"]["nodes"].as_u64().unwrap() + 1,
                v["nodes"].as_u64().unwrap(),
                "node counts reconcile too, at limit {limit}"
            );
            assert_eq!(v["rest"]["count"], (N - limit.min(N)) as u64);
            // The head is the HEAVIEST children, not an arbitrary slice.
            assert_eq!(kids[0]["name"], "c059", "heaviest first at limit {limit}");
        }
    }

    #[test]
    fn sizes_of_a_missing_node_is_a_404() {
        let f = fixture("sizes404", None);
        assert_eq!(get(&f, "/api/sizes?name=nope").0, 404);
    }

    #[test]
    fn a_deep_tree_rolls_up_without_walking_it_twice() {
        // The bucketing memoises the climb, so correctness has to hold when a
        // node is many levels below the bucket it lands in -- and when many
        // nodes share the same ancestry.
        const N: usize = 300;
        let dir = std::env::temp_dir().join(format!("qdb_dash_deep_{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();
        let slots = slot_count_for(N as u64 + 2);
        let mut w =
            SegmentWriter::create(&dir, 4, 8 << 20, seg_backed_for(0, slots), slots, 0).unwrap();

        // root -> a -> chain of N nodes, each with a 10-byte document.
        let mk = |name: &str, rel: &str, father: Option<&str>, size: i64| {
            let langs = vec![LangDoc {
                lang: "",
                doc: b"0123456789",
                corrupt: false,
                doc_mtime: 0,
                doc_size: size,
                image: &[],
            }];
            encode_record(
                &RecordInput {
                    name,
                    rel_path: rel,
                    father,
                    generation: 1,
                    mtime: 0,
                    children: &[],
                    inlinks: &[],
                    langs: &langs,
                },
                false,
            )
        };
        w.upsert("root", &mk("root", "root", None, 5)).unwrap();
        w.upsert("a", &mk("a", "root/a", Some("root"), 7)).unwrap();
        let mut prev = "a".to_string();
        for i in 0..N {
            let name = format!("d{i}");
            let rel = format!("root/a/{name}");
            w.upsert(&name, &mk(&name, &rel, Some(&prev), 10)).unwrap();
            prev = name;
        }
        w.set_counts(N as u64 + 2, 0, 0, 0, 0);
        w.publish_ready();

        let f = Fixture {
            cfg: Config {
                addr: "127.0.0.1:0".into(),
                shm_path: dir.join("metrics.shm"),
                data_dir: dir.clone(),
                token: None,
                base_path: String::new(),
            },
            dir,
        };

        let (status, v) = get(&f, "/api/sizes?name=root");
        assert_eq!(status, 200);
        assert_eq!(v["own"], 5);
        let kids = v["children"].as_array().unwrap();
        assert_eq!(kids.len(), 1, "root has one child, however deep the chain below it");
        assert_eq!(kids[0]["name"], "a");
        assert_eq!(kids[0]["total"], 7 + (N as u64) * 10, "the whole chain rolls into `a`");
        assert_eq!(kids[0]["nodes"], N as u64 + 1);
        assert_eq!(v["total"], 5 + 7 + (N as u64) * 10);
    }

    #[test]
    fn a_base_path_is_normalised_to_one_spelling() {
        // Whatever an operator or a chart writes, the comparisons downstream
        // assume exactly one shape.
        assert_eq!(normalize_base(""), "");
        assert_eq!(normalize_base("/"), "");
        assert_eq!(normalize_base("qdb"), "/qdb");
        assert_eq!(normalize_base("/qdb"), "/qdb");
        assert_eq!(normalize_base("/qdb/"), "/qdb");
        assert_eq!(normalize_base("  /qdb/  "), "/qdb");
        assert_eq!(normalize_base("/a/b/"), "/a/b");
    }

    #[test]
    fn every_route_answers_under_a_base_path() {
        // An Ingress that routes /qdb here does NOT strip it, so the whole
        // route table has to work with the prefix still attached.
        let mut f = fixture("baseroutes", None);
        f.cfg.base_path = normalize_base("/qdb");

        let (status, v) = get(&f, "/qdb/api/node?name=home");
        assert_eq!(status, 200);
        assert_eq!(v["rel_path"], "home");

        assert_eq!(dispatch(&f.cfg, stub_stats, "GET", "/qdb/", None).status, 200);
        assert_eq!(dispatch(&f.cfg, stub_stats, "GET", "/qdb/app.js", None).status, 200);
        assert_eq!(dispatch(&f.cfg, stub_stats, "GET", "/qdb/healthz", None).status, 200);
        assert_eq!(get(&f, "/qdb/api/list").0, 200);
    }

    #[test]
    fn the_bare_prefix_redirects_to_its_trailing_slash() {
        // Without the slash a browser resolves the page's own relative assets
        // against `/`, so they leave the prefix and 404. The redirect is what
        // makes a plain ingress path work with no middleware.
        let mut f = fixture("baseslash", None);
        f.cfg.base_path = normalize_base("/qdb");

        let r = dispatch(&f.cfg, stub_stats, "GET", "/qdb", None);
        assert_eq!(r.status, 301);
        let head = String::from_utf8(r.encode(true)).unwrap();
        assert!(head.contains("Location: /qdb/"), "{head}");

        // The query string has to survive it, or a token pasted into the URL is
        // lost exactly once per visit.
        let r = dispatch(&f.cfg, stub_stats, "GET", "/qdb?token=abc", None);
        let head = String::from_utf8(r.encode(true)).unwrap();
        assert!(head.contains("Location: /qdb/?token=abc"), "{head}");
    }

    #[test]
    fn nothing_outside_the_base_path_is_served() {
        let mut f = fixture("baseoutside", None);
        f.cfg.base_path = normalize_base("/qdb");

        // The root is no longer this dashboard's; the app at / owns it.
        assert_eq!(dispatch(&f.cfg, stub_stats, "GET", "/", None).status, 404);
        assert_eq!(get(&f, "/api/node?name=home").0, 404);
        // A path that merely starts with the same letters is a DIFFERENT path.
        assert_eq!(dispatch(&f.cfg, stub_stats, "GET", "/qdbx/api/roots", None).status, 404);
    }

    #[test]
    fn the_token_still_gates_a_prefixed_dashboard() {
        // The prefix is routing, not authorisation; stripping it must not skip
        // the check.
        let mut f = fixture("basetoken", Some("s3cret"));
        f.cfg.base_path = normalize_base("/qdb");
        assert_eq!(dispatch(&f.cfg, stub_stats, "GET", "/qdb/api/roots", None).status, 401);
        assert_eq!(get(&f, "/qdb/api/roots?token=s3cret").0, 200);
        assert_eq!(dispatch(&f.cfg, stub_stats, "GET", "/qdb/healthz", None).status, 200);
    }

    #[test]
    fn the_page_asks_for_its_api_relatively() {
        // The other half of the prefix story: an absolute "/api/..." in the
        // page would leave the prefix no matter what the server does with it.
        let f = fixture("relative", None);
        let js = String::from_utf8(dispatch(&f.cfg, stub_stats, "GET", "/app.js", None).body).unwrap();
        assert!(js.contains("api/summary"), "the page must call the API");
        assert!(
            !js.contains("api(\"/"),
            "an api() call starting with / would leave the base path"
        );
    }

    #[test]
    fn names_survive_percent_encoding() {
        // Node names are directory names: spaces, plus signs and non-ASCII are
        // all legal, and every one of them has to round-trip through the query
        // string or the tree cannot link to its own nodes.
        assert_eq!(percent_decode("a%20b"), "a b");
        assert_eq!(percent_decode("a+b"), "a b");
        assert_eq!(percent_decode("caf%C3%A9"), "café");
        assert_eq!(percent_decode("100%"), "100%", "a stray % is data, not a syntax error");
        assert_eq!(percent_decode("%zz"), "%zz");
        // A percent escape landing mid-codepoint must not panic: this input is
        // entirely client-controlled.
        assert_eq!(percent_decode("%C3"), "\u{fffd}");
    }

    #[test]
    fn the_query_parser_takes_the_first_value() {
        let q = Query::parse("name=a&name=b&empty=&flag");
        assert_eq!(q.get("name").as_deref(), Some("a"));
        assert_eq!(q.get("empty").as_deref(), Some(""));
        assert_eq!(q.get("flag").as_deref(), Some(""));
        assert_eq!(q.get("missing"), None);
    }

    #[test]
    fn responses_refuse_to_be_sniffed_or_framed() {
        // The body is site content rendered into a page; the headers are what
        // keep that from being someone else's problem.
        let f = fixture("headers", None);
        let head = String::from_utf8(
            dispatch(&f.cfg, stub_stats, "GET", "/", None).encode(true),
        )
        .unwrap();
        assert!(head.contains("X-Content-Type-Options: nosniff"));
        assert!(head.contains("frame-ancestors 'none'"));
        assert!(head.contains("default-src 'none'"));
        assert!(head.contains("Cache-Control: no-store"));
    }
}
