//! The daemon's in-RAM tree model: the authoritative picture of every node
//! (path, father, children, links, raw JSON docs) from which the shared-memory
//! segment is projected. Absorbs the walk/doc-load half of the old SQLite
//! `reindex.rs`. PHP-free — shared by `qdbd` via `#[path]` include.
#![allow(dead_code)]

use std::collections::{BTreeSet, HashMap, HashSet};
use std::path::{Path, PathBuf};

use serde_json::Value;

use crate::config::Config;
use crate::image;
use crate::shm::{self, LangDoc, RecordInput};
use crate::store;

pub struct DocModel {
    pub lang: String,
    /// Raw file bytes — kept ONLY when this document has no image.
    ///
    /// The image is a complete representation of the document, so storing both
    /// stored the same content twice (on a production tree of ~111k documents,
    /// 39.7 MB of segment, and the same again in this heap). But the segment must always be able to
    /// answer SOMETHING, or a document with no image has nothing resident and
    /// every read of it goes to disk. That is not hypothetical: `image=0` is a
    /// documented kill switch, and a document can also exceed `image_max_doc`
    /// or nest deeper than the encoder allows.
    ///
    /// So the two are alternatives, never both: imaged documents carry no raw
    /// (the common case, and where the saving comes from), un-imaged ones keep
    /// their bytes and the fast path degrades to a parse instead of to a
    /// syscall. Empty for a corrupt document either way — readers surface
    /// CORRUPT_JSON from the flag.
    pub raw: Vec<u8>,
    pub corrupt: bool,
    pub mtime: i64,
    /// The document's size ON DISK. The bytes themselves are deliberately not
    /// kept: the daemon used to hold every document's raw JSON for the life of
    /// the model purely to hand it back to `encode()` on every republish — on a
    /// production tree of ~111k documents, 39.7 MB of the daemon's heap
    /// duplicating content the image already represents completely. `image` below is the
    /// only in-memory copy now, and the file is the only byte-exact one.
    pub size: i64,
    /// Pre-decoded ID-image (`image::encode`), built ONCE here when the
    /// document is read and carried for the life of this model entry. Empty
    /// when images are disabled, the document is over `image_max_doc`, or it
    /// would not encode.
    ///
    /// It must never be rebuilt in `encode()`: that runs on every republish,
    /// and `apply_link`, `refresh_children` and `publish_full` republish
    /// constantly — re-imaging there would make a link change cost a full
    /// re-encode of every document in the tree.
    ///
    /// Its string slots hold `image::Interner` IDs, not offsets: the strings
    /// themselves live once in `Model::strings` and are placed into whichever
    /// segment is being written by `image::resolve` (see `NodeModel::encode`).
    /// That is what keeps this vector small — it holds structure, not text.
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

    /// The node's documents as they are ON DISK. Not what the segment holds —
    /// see `SegmentWriter::set_counts`, which reports both.
    pub fn doc_bytes(&self) -> u64 {
        self.docs
            .iter()
            .filter(|d| !d.corrupt)
            .map(|d| d.size.max(0) as u64)
            .sum()
    }

    /// Raw JSON this node actually puts IN the segment — non-zero only for
    /// documents that could not be imaged.
    pub fn raw_bytes(&self) -> u64 {
        self.docs.iter().map(|d| d.raw.len() as u64).sum()
    }

    pub fn img_bytes(&self) -> u64 {
        self.docs.iter().map(|d| d.image.len() as u64).sum()
    }

    /// Upper-bound size of this node's record, without encoding it.
    ///
    /// `publish_full` used to encode the whole tree into a `Vec<(String,
    /// Vec<u8>)>` just to sum the lengths, then hold that copy alive while it
    /// created and filled the new segment — so a compaction's peak footprint
    /// carried the model, a second copy of every document, and both segment
    /// mappings at once. This lets it size the segment from a pass that
    /// allocates nothing, and then stream one record at a time.
    ///
    /// Mirrors `shm::encode_record`'s layout, rounded up: each field is
    /// over-counted by its alignment padding rather than tracked exactly, which
    /// is the right side to be wrong on for a size hint.
    pub fn encoded_size_hint(&self) -> u64 {
        let mut n = shm::REC_FIXED
            + self.name.len()
            + self.rel_path.len()
            + self.father.as_deref().map_or(0, str::len);
        n += self.children.iter().map(|(c, _)| c.len() + 2).sum::<usize>();
        n += self.inlinks.iter().map(|i| i.len() + 2).sum::<usize>();
        for d in &self.docs {
            // +8 twice: the image is individually 8-aligned inside the record,
            // and the record itself is padded to 8 at the end.
            n += shm::LANG_FIXED + d.lang.len() + d.raw.len() + d.image.len() + 8;
        }
        (n + 8) as u64
    }

    /// Serialize into the on-segment record format, resolving every image's
    /// string IDs into offsets in the segment being written.
    ///
    /// `place` takes an `image::Interner` ID and the string's bytes and returns
    /// its offset in that segment, appending it to the arena on first use —
    /// `shm::SegmentWriter::place_string` is the real implementation. Returning
    /// `None` means the arena could not take the string, so the whole record is
    /// abandoned and the caller compacts: publishing a record whose image was
    /// only half-resolved would hand PHP an interner ID as an address.
    pub fn encode(
        &self,
        strings: &image::Interner,
        mut place: impl FnMut(u32, &str) -> Option<u32>,
    ) -> Option<Vec<u8>> {
        let inlinks: Vec<String> = self.inlinks.iter().cloned().collect();
        // One resolved copy per document, alive only until the record bytes are
        // built. The model keeps the ID-image; only the segment gets offsets.
        let mut resolved: Vec<Vec<u8>> = Vec::with_capacity(self.docs.len());
        for d in &self.docs {
            if d.image.is_empty() {
                resolved.push(Vec::new());
                continue;
            }
            let r = image::resolve(&d.image, |id| {
                let s = strings.get(id)?;
                place(id, s)
            })?;
            resolved.push(r);
        }
        let langs: Vec<LangDoc> = self
            .docs
            .iter()
            .zip(resolved.iter())
            .map(|(d, img)| LangDoc {
                lang: &d.lang,
                // Empty whenever the document is imaged — see `DocModel::raw`.
                // `shm::LANG_HAS_RAW` records which way it went, so readers
                // never confuse "not stored" with "no document".
                doc: &d.raw,
                corrupt: d.corrupt,
                doc_mtime: d.mtime,
                doc_size: d.size,
                image: img,
            })
            .collect();
        Some(shm::encode_record(
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
        ))
    }
}

#[derive(Default)]
pub struct Model {
    pub nodes: HashMap<String, NodeModel>,
    /// (container, target) edges — symlinks to node dirs under the root.
    pub links: HashSet<(String, String)>,
    /// Every distinct string in every document, held exactly once. Images
    /// reference entries here by ID; the segment writer places the bytes.
    ///
    /// Modelled over a production tree of ~111k documents, this is 87,585
    /// entries / ~9.8 MB against the 2,112,806 per-document `zend_string`s /
    /// 89.5 MB the v2 layout wrote — see the `image` module docs.
    pub strings: image::Interner,
}

impl Model {
    pub fn doc_bytes(&self) -> u64 {
        self.nodes.values().map(NodeModel::doc_bytes).sum()
    }

    pub fn img_bytes(&self) -> u64 {
        self.nodes.values().map(NodeModel::img_bytes).sum()
    }

    pub fn raw_bytes(&self) -> u64 {
        self.nodes.values().map(NodeModel::raw_bytes).sum()
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
pub fn load_docs(cfg: &Config, path: &Path, strings: &mut image::Interner) -> Vec<DocModel> {
    let mut docs = Vec::new();
    for lang in store::langs_of(path) {
        if let Ok(Some((raw, fstat))) = store::read_doc(path, &lang) {
            let parsed = serde_json::from_str::<Value>(&raw);
            let corrupt = parsed.is_err();
            let image = match &parsed {
                Ok(v) if cfg.image && raw.len() <= cfg.image_max_doc => {
                    image::encode(v, strings)
                }
                _ => Vec::new(),
            };
            docs.push(DocModel {
                lang,
                // Raw only as the image's stand-in, never alongside it.
                raw: if corrupt || !image.is_empty() {
                    Vec::new()
                } else {
                    raw.into_bytes()
                },
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
pub fn load_node(
    cfg: &Config,
    name: &str,
    path: &Path,
    father: Option<String>,
    strings: &mut image::Interner,
) -> NodeModel {
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
        load_docs(cfg, path, strings)
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
        let mut node = load_node(cfg, &wn.name, &wn.path, wn.father, &mut m.strings);
        node.generation = next_gen();
        m.nodes.insert(wn.name.clone(), node);
    }
    m.links = links.into_iter().collect();
    m.rebuild_inlinks();
    m
}
