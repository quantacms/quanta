//! The daemon's in-RAM tree model: the authoritative picture of every node
//! (path, father, children, links, raw JSON docs) from which the shared-memory
//! segment is projected. Absorbs the walk/doc-load half of the old SQLite
//! `reindex.rs`. PHP-free — shared by `qdbd` via `#[path]` include.
#![allow(dead_code)]

use std::collections::{BTreeSet, HashMap, HashSet};
use std::path::{Path, PathBuf};

use serde_json::Value;

use crate::config::Config;
use crate::shm::{self, LangDoc, RecordInput};
use crate::store;

pub struct DocModel {
    pub lang: String,
    /// Raw file bytes; empty when `corrupt` (present but unparseable — readers
    /// must surface CORRUPT_JSON, so the language entry itself is kept).
    pub raw: Vec<u8>,
    pub corrupt: bool,
    pub mtime: i64,
    pub size: i64,
    /// Pre-decoded image (`image::encode`), built ONCE here when the document
    /// is read and carried for the life of this model entry. Empty when images
    /// are disabled, the document is over `image_max_doc`, or it would not
    /// encode.
    ///
    /// It must never be rebuilt in `encode()`: that runs on every republish,
    /// and `apply_link`, `refresh_children` and `publish_full` republish
    /// constantly — re-imaging there would make a link change cost a full
    /// re-encode of every document in the tree.
    pub image: Vec<u8>,
}

pub struct NodeModel {
    pub name: String,
    pub rel_path: String,
    pub father: Option<String>,
    pub generation: u64,
    pub mtime: i64,
    /// Sorted (name, is_link), `_`-hidden included — `store::list_children`.
    pub children: Vec<(String, bool)>,
    /// Container names symlinking to this node (sorted by BTreeSet).
    pub inlinks: BTreeSet<String>,
    pub docs: Vec<DocModel>,
}

impl NodeModel {
    pub fn abs_path(&self, cfg: &Config) -> PathBuf {
        cfg.root.join(&self.rel_path)
    }

    pub fn doc_bytes(&self) -> u64 {
        self.docs.iter().map(|d| d.raw.len() as u64).sum()
    }

    pub fn img_bytes(&self) -> u64 {
        self.docs.iter().map(|d| d.image.len() as u64).sum()
    }

    /// Serialize into the on-segment record format.
    pub fn encode(&self) -> Vec<u8> {
        let inlinks: Vec<String> = self.inlinks.iter().cloned().collect();
        let langs: Vec<LangDoc> = self
            .docs
            .iter()
            .map(|d| LangDoc {
                lang: &d.lang,
                doc: &d.raw,
                corrupt: d.corrupt,
                doc_mtime: d.mtime,
                doc_size: d.size,
                image: &d.image,
            })
            .collect();
        shm::encode_record(
            &RecordInput {
                name: &self.name,
                rel_path: &self.rel_path,
                father: self.father.as_deref(),
                generation: self.generation,
                mtime: self.mtime,
                children: &self.children,
                inlinks: &inlinks,
                langs: &langs,
            },
            false,
        )
    }
}

#[derive(Default)]
pub struct Model {
    pub nodes: HashMap<String, NodeModel>,
    /// (container, target) edges — symlinks to node dirs under the root.
    pub links: HashSet<(String, String)>,
}

impl Model {
    pub fn doc_bytes(&self) -> u64 {
        self.nodes.values().map(NodeModel::doc_bytes).sum()
    }

    pub fn img_bytes(&self) -> u64 {
        self.nodes.values().map(NodeModel::img_bytes).sum()
    }

    /// Recompute every node's inlink set from the link edges.
    pub fn rebuild_inlinks(&mut self) {
        for n in self.nodes.values_mut() {
            n.inlinks.clear();
        }
        for (container, target) in &self.links {
            if let Some(n) = self.nodes.get_mut(target) {
                n.inlinks.insert(container.clone());
            }
        }
    }
}

/// Read one node's docs (every `data*.json`). Corrupt files are kept as
/// corrupt-flagged entries so readers can throw CORRUPT_JSON (contract §7),
/// while `langs` listings still show the language.
///
/// The parse here is not new work: this function has always parsed every
/// document just to set `corrupt`, then thrown the result away. Building the
/// pre-decoded image reuses that `Value`, so the only added cost is the encode
/// itself — no extra I/O and no second parse.
pub fn load_docs(cfg: &Config, path: &Path) -> Vec<DocModel> {
    let mut docs = Vec::new();
    for lang in store::langs_of(path) {
        if let Ok(Some((raw, fstat))) = store::read_doc(path, &lang) {
            let parsed = serde_json::from_str::<Value>(&raw);
            let corrupt = parsed.is_err();
            let image = match &parsed {
                Ok(v) if cfg.image && raw.len() <= cfg.image_max_doc => crate::image::encode(v),
                _ => Vec::new(),
            };
            docs.push(DocModel {
                lang,
                raw: if corrupt { Vec::new() } else { raw.into_bytes() },
                corrupt,
                mtime: fstat.mtime,
                size: fstat.size,
                image,
            });
        }
    }
    docs
}

/// Build a NodeModel from disk (docs, children, mtime). `generation` is
/// assigned by the caller (the daemon owns the counter); inlinks are filled by
/// [`Model::rebuild_inlinks`] or the daemon's incremental updates.
pub fn load_node(cfg: &Config, name: &str, path: &Path, father: Option<String>) -> NodeModel {
    let rel_path = path
        .strip_prefix(&cfg.root)
        .map(|p| p.to_string_lossy().to_string())
        .unwrap_or_else(|_| path.to_string_lossy().to_string());
    // Payload subtrees (assets/files) are indexed for path resolution but their
    // documents are never read into memory — they hold static/binary payload,
    // not node content (store::PAYLOAD_DIRS).
    let docs = if store::in_payload_subtree(&rel_path) {
        Vec::new()
    } else {
        load_docs(cfg, path)
    };
    NodeModel {
        name: name.to_string(),
        rel_path,
        father,
        generation: 0,
        mtime: store::node_mtime(path),
        children: store::list_children(path),
        inlinks: BTreeSet::new(),
        docs,
    }
}

/// Walk `base` and drop duplicate node names / duplicate links (first wins,
/// mirroring legacy behavior). Presence only — no docs.
pub fn walk_dedup(
    cfg: &Config,
    base: &Path,
) -> (Vec<store::WalkNode>, Vec<(String, String)>) {
    let mut nodes = Vec::new();
    let mut links = Vec::new();
    store::walk(cfg, base, &mut nodes, &mut links);
    let mut seen = HashSet::new();
    nodes.retain(|n| seen.insert(n.name.clone()));
    let mut seen_links = HashSet::new();
    links.retain(|l| seen_links.insert(l.clone()));
    (nodes, links)
}

/// Full model load: one tree walk + every document read. This is the only
/// filesystem bulk-read in the system — it runs at daemon boot and on
/// `reindex`, never on the serving path.
pub fn build_from_disk(cfg: &Config, mut next_gen: impl FnMut() -> u64) -> Model {
    let (walk_nodes, links) = walk_dedup(cfg, &cfg.root);
    let mut m = Model::default();
    for wn in walk_nodes {
        let mut node = load_node(cfg, &wn.name, &wn.path, wn.father);
        node.generation = next_gen();
        m.nodes.insert(wn.name.clone(), node);
    }
    m.links = links.into_iter().collect();
    m.rebuild_inlinks();
    m
}
