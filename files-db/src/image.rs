//! Pre-decoded document image: a JSON document flattened into a form a PHP
//! worker can turn into zvals in one linear pass — no tokenizing, no number
//! parsing, no UTF-8 revalidation, no string allocation.
//!
//! Written by `qdbd` (which already parses every document to detect corruption,
//! see `model::load_docs`, so the encode is nearly free) and read by the
//! extension straight out of its read-only mapping.
//!
//! **No pointers anywhere.** Every reference is an offset — within the image
//! for value nodes, within the whole SEGMENT for strings — so the same bytes
//! are valid at whatever address each worker happens to map the segment at.
//! String entries are ready-made `zend_string`s (`php_abi::push_zend_string`),
//! which is what lets a zval point directly into shared memory instead of
//! copying.
//!
//! ## Strings live in the segment, not in the image (layout v3)
//!
//! Until v2 each document carried its own string table, so every one of a tree
//! of same-shaped nodes re-emitted `"title"`, `"language"`, `"permissions"` as
//! its own 32-byte `zend_string`. Modelled over hili's production tree
//! (111,124 documents, `tests/bench/image-size.py --dedup`):
//!
//! ```text
//! per-document string tables : 2,112,806 entries, 89,500,320 B
//! segment-wide string table  :    87,585 entries,  9,844,208 B
//! saving                     :               79,656,112 B  (89.0%)
//! ```
//!
//! So strings moved out. The encoder now emits, in every string slot, a
//! DAEMON-GLOBAL STRING ID from [`Interner`]; [`resolve`] later rewrites those
//! ids into offsets in the segment being written, placing each string in the
//! arena the first time that segment needs it. Two consequences worth stating
//! plainly:
//!
//! * an id-image is NOT readable — the header carries [`FLAG_STRING_IDS`] and
//!   [`Image::open`] refuses it. Publishing an unresolved image would hand PHP
//!   a string id as an address, so this is a hard error, not a fallback;
//! * string offsets are unique across a whole epoch, where they used to
//!   collide between documents at small image-relative values. That is what
//!   makes a per-request memo of interned keys correct (see `interned_key` in
//!   lib.rs, whose comment records why it was NOT safe before).
//!
//! PHP-free, like `php_abi`: `qdbd` links this without linking PHP.

use std::collections::HashMap;

use serde_json::Value;

use crate::php_abi;

pub const MAGIC: u32 = 0x4942_4451; // "QDBI"
/// 2 = strings are segment-resident (v1 carried them inside the image).
pub const VERSION: u16 = 2;
pub const HEADER_SIZE: usize = 16;

/// Bit 0: string entries are ready-made `zend_string` structs.
pub const FLAG_ZEND_STRINGS: u16 = 1;
/// Bit 1: every string slot still holds an [`Interner`] ID, not an offset —
/// the image came out of [`encode`] and has not been through [`resolve`] yet.
/// Readers must refuse it.
pub const FLAG_STRING_IDS: u16 = 2;

/// Mirrors PHP's own nesting limit for `json_decode`. The materializer
/// recurses, so a hostile document must not be able to blow the C stack.
pub const MAX_DEPTH: u32 = 512;

/// Refuse absurd container sizes: `_zend_new_array` takes a u32, and a bad
/// count read out of a damaged image must not become a huge allocation.
pub const MAX_COUNT: usize = 16_777_216;

pub const TAG_NULL: u8 = 0;
pub const TAG_FALSE: u8 = 1;
pub const TAG_TRUE: u8 = 2;
pub const TAG_LONG: u8 = 3;
pub const TAG_DOUBLE: u8 = 4;
pub const TAG_STR: u8 = 5;
pub const TAG_LIST: u8 = 6;
pub const TAG_MAP: u8 = 7;

/// Size of one value node's fixed part.
const NODE_SIZE: usize = 16;

#[derive(Debug)]
pub enum ImageError {
    TooDeep,
    TooLarge,
}

// ---------------------------------------------------------------------------
// Interner (daemon side)
// ---------------------------------------------------------------------------

/// The daemon's one table of distinct document strings, shared by every
/// document in the tree. Owns the bytes exactly once; images reference entries
/// by index.
///
/// **IDs are never reused or renumbered.** An image is encoded once, at
/// document load, and then republished into many segments over the daemon's
/// life (`apply_link`, `refresh_children` and `publish_full` all republish
/// without re-imaging) — renumbering would invalidate every image already in
/// the model. The cost of that choice is that a string which no live document
/// references any more keeps its slot in this table until the daemon restarts.
/// It does NOT keep its bytes in the segment: [`resolve`] places a string only
/// when a document being written actually asks for it, so each compaction
/// naturally drops the unreferenced ones.
#[derive(Default)]
pub struct Interner {
    map: HashMap<Box<str>, u32>,
    strings: Vec<Box<str>>,
}

// The daemon builds and queries the interner; the extension only ever reads
// images that were already resolved against it. Everything here is therefore
// dead code in the cdylib target and live in `qdbd` — the split is the point,
// not an oversight.
#[allow(dead_code)]
impl Interner {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// ID for `s`, interning it if new.
    pub fn intern(&mut self, s: &str) -> u32 {
        if let Some(id) = self.map.get(s) {
            return *id;
        }
        let id = self.strings.len() as u32;
        let boxed: Box<str> = s.into();
        self.strings.push(boxed.clone());
        self.map.insert(boxed, id);
        id
    }

    #[must_use]
    pub fn get(&self, id: u32) -> Option<&str> {
        self.strings.get(id as usize).map(|s| &**s)
    }

    #[must_use]
    pub fn len(&self) -> usize {
        self.strings.len()
    }

    #[must_use]
    pub fn is_empty(&self) -> bool {
        self.strings.is_empty()
    }

    /// Bytes of text held (diagnostics; excludes the map/vec overhead).
    #[must_use]
    pub fn text_bytes(&self) -> usize {
        self.strings.iter().map(|s| s.len()).sum()
    }
}

// ---------------------------------------------------------------------------
// Encoder (daemon side)
// ---------------------------------------------------------------------------

/// Layout produced here:
///
/// ```text
/// header (16 B)
///   0  magic      u32   "QDBI"
///   4  version    u16
///   6  flags      u16
///   8  node_count u32   value nodes emitted (diagnostic / validation)
///   12 root_off   u32   offset of the root value node
///
/// value node (16 B, 8-aligned)
///   0  tag        u8
///   1  _pad       u8
///   2  _pad       u16
///   4  count      u32   list: elements | map: pairs | else 0
///   8  payload    u64   long: i64 | double: f64 bits | str: str_ref
///                       list: elems_off | map: pairs_off
///
/// list elements: count x u32 node_off
/// map pairs:     count x { u32 key_str_ref, u32 val_node_off }
/// ```
///
/// A `str_ref` is an [`Interner`] ID while [`FLAG_STRING_IDS`] is set, and a
/// SEGMENT-relative offset of a `zend_string` (see `php_abi::push_zend_string`)
/// once [`resolve`] has cleared that flag. Node and table offsets are always
/// image-relative and are never rewritten.
pub struct Encoder<'i> {
    buf: Vec<u8>,
    interner: &'i mut Interner,
    node_count: u32,
}

impl<'i> Encoder<'i> {
    fn new(interner: &'i mut Interner) -> Self {
        let mut buf = Vec::with_capacity(256);
        buf.resize(HEADER_SIZE, 0);
        Self {
            buf,
            interner,
            node_count: 0,
        }
    }

    /// Reserve a value node, returning its offset. Filled in by the caller.
    fn alloc_node(&mut self) -> u32 {
        while self.buf.len() % 8 != 0 {
            self.buf.push(0);
        }
        let off = self.buf.len() as u32;
        self.buf.resize(self.buf.len() + NODE_SIZE, 0);
        self.node_count += 1;
        off
    }

    fn write_node(&mut self, off: u32, tag: u8, count: u32, payload: u64) {
        let o = off as usize;
        self.buf[o] = tag;
        self.buf[o + 4..o + 8].copy_from_slice(&count.to_le_bytes());
        self.buf[o + 8..o + 16].copy_from_slice(&payload.to_le_bytes());
    }

    fn encode_value(&mut self, v: &Value, depth: u32) -> Result<u32, ImageError> {
        if depth > MAX_DEPTH {
            return Err(ImageError::TooDeep);
        }
        let off = self.alloc_node();
        match v {
            Value::Null => self.write_node(off, TAG_NULL, 0, 0),
            Value::Bool(false) => self.write_node(off, TAG_FALSE, 0, 0),
            Value::Bool(true) => self.write_node(off, TAG_TRUE, 0, 0),
            Value::Number(n) => {
                if let Some(i) = n.as_i64() {
                    self.write_node(off, TAG_LONG, 0, i as u64);
                } else {
                    let d = n.as_f64().unwrap_or(0.0);
                    self.write_node(off, TAG_DOUBLE, 0, d.to_bits());
                }
            }
            Value::String(s) => {
                let id = self.interner.intern(s);
                self.write_node(off, TAG_STR, 0, u64::from(id));
            }
            Value::Array(items) => {
                if items.len() > MAX_COUNT {
                    return Err(ImageError::TooLarge);
                }
                // Children first, then the offset table: an element's own
                // encoding appends nodes, so the table cannot be contiguous
                // with the recursion.
                let mut offs = Vec::with_capacity(items.len());
                for item in items {
                    offs.push(self.encode_value(item, depth + 1)?);
                }
                while self.buf.len() % 4 != 0 {
                    self.buf.push(0);
                }
                let table = self.buf.len() as u32;
                for o in &offs {
                    self.buf.extend_from_slice(&o.to_le_bytes());
                }
                self.write_node(off, TAG_LIST, items.len() as u32, u64::from(table));
            }
            Value::Object(map) => {
                if map.len() > MAX_COUNT {
                    return Err(ImageError::TooLarge);
                }
                // serde_json with `preserve_order` keeps insertion order and
                // already applied JSON's last-key-wins, so the pairs are unique
                // here — which the materializer relies on when it inserts with
                // zend_hash_add_new (which assumes no duplicates).
                let mut pairs = Vec::with_capacity(map.len());
                for (k, val) in map {
                    let kid = self.interner.intern(k);
                    let vo = self.encode_value(val, depth + 1)?;
                    pairs.push((kid, vo));
                }
                while self.buf.len() % 4 != 0 {
                    self.buf.push(0);
                }
                let table = self.buf.len() as u32;
                for (kid, vo) in &pairs {
                    self.buf.extend_from_slice(&kid.to_le_bytes());
                    self.buf.extend_from_slice(&vo.to_le_bytes());
                }
                self.write_node(off, TAG_MAP, map.len() as u32, u64::from(table));
            }
        }
        Ok(off)
    }
}

/// Encode one decoded document into an ID-image: every string slot holds an
/// [`Interner`] ID and must be run through [`resolve`] before it can be
/// published. Returns an empty vec if the document cannot be imaged (too deep,
/// too large) — callers treat that as "no image", never as an error.
#[must_use]
pub fn encode(v: &Value, interner: &mut Interner) -> Vec<u8> {
    let mut e = Encoder::new(interner);
    let Ok(root) = e.encode_value(v, 0) else {
        return Vec::new();
    };
    let node_count = e.node_count;
    let buf = &mut e.buf;
    buf[0..4].copy_from_slice(&MAGIC.to_le_bytes());
    buf[4..6].copy_from_slice(&VERSION.to_le_bytes());
    buf[6..8].copy_from_slice(&(FLAG_ZEND_STRINGS | FLAG_STRING_IDS).to_le_bytes());
    buf[8..12].copy_from_slice(&node_count.to_le_bytes());
    buf[12..16].copy_from_slice(&root.to_le_bytes());
    e.buf
}

// ---------------------------------------------------------------------------
// Resolver (daemon side; runs once per record per segment)
// ---------------------------------------------------------------------------

fn wr_u32(b: &mut [u8], off: usize, v: u32) -> Option<()> {
    b.get_mut(off..off + 4)?.copy_from_slice(&v.to_le_bytes());
    Some(())
}

fn wr_u64(b: &mut [u8], off: usize, v: u64) -> Option<()> {
    b.get_mut(off..off + 8)?.copy_from_slice(&v.to_le_bytes());
    Some(())
}

/// Rewrite every string reference in an ID-image into a segment offset,
/// returning the publishable image.
///
/// `place` is called once per string reference with the [`Interner`] ID and
/// must return the offset of that string's `zend_string` inside the segment
/// being written, appending it to the arena if it is not there yet. Returning
/// `None` (arena full, id unknown) aborts the whole resolve: a half-rewritten
/// image would hand PHP an ID as an address, so there is no partial success.
///
/// Walks the value tree rather than carrying a relocation table. The table
/// would be ~4 bytes per string reference — 8 MB of daemon RSS on hili's tree —
/// to save a walk that only runs when a record is actually written, which is
/// the wrong side of that trade.
#[must_use]
pub fn resolve(img: &[u8], mut place: impl FnMut(u32) -> Option<u32>) -> Option<Vec<u8>> {
    if img.len() < HEADER_SIZE {
        return None;
    }
    let flags = u16::from_le_bytes([img[6], img[7]]);
    if flags & FLAG_STRING_IDS == 0 {
        // Already resolved. Rewriting offsets as if they were IDs is exactly
        // the corruption this flag exists to prevent, so refuse rather than
        // silently double-resolve.
        return None;
    }
    let root = u32::from_le_bytes([img[12], img[13], img[14], img[15]]);
    let mut out = img.to_vec();
    walk_resolve(&mut out, root, 0, &mut place)?;
    let cleared = (flags & !FLAG_STRING_IDS).to_le_bytes();
    out[6..8].copy_from_slice(&cleared);
    Some(out)
}

fn walk_resolve(
    buf: &mut Vec<u8>,
    off: u32,
    depth: u32,
    place: &mut impl FnMut(u32) -> Option<u32>,
) -> Option<()> {
    if depth > MAX_DEPTH {
        return None;
    }
    let o = off as usize;
    if o % 8 != 0 || o.checked_add(NODE_SIZE)? > buf.len() {
        return None;
    }
    let tag = *buf.get(o)?;
    let count = rd_u32(buf, o + 4)?;
    let payload = rd_u64(buf, o + 8)?;
    match tag {
        TAG_STR => {
            let id = u32::try_from(payload).ok()?;
            let so = place(id)?;
            wr_u64(buf, o + 8, u64::from(so))?;
        }
        TAG_LIST => {
            if count as usize > MAX_COUNT {
                return None;
            }
            let table = u32::try_from(payload).ok()? as usize;
            let end = table.checked_add((count as usize).checked_mul(4)?)?;
            if end > buf.len() {
                return None;
            }
            for i in 0..count as usize {
                let eo = rd_u32(buf, table + i * 4)?;
                walk_resolve(buf, eo, depth + 1, place)?;
            }
        }
        TAG_MAP => {
            if count as usize > MAX_COUNT {
                return None;
            }
            let table = u32::try_from(payload).ok()? as usize;
            let end = table.checked_add((count as usize).checked_mul(8)?)?;
            if end > buf.len() {
                return None;
            }
            for i in 0..count as usize {
                let kid = rd_u32(buf, table + i * 8)?;
                let vo = rd_u32(buf, table + i * 8 + 4)?;
                let so = place(kid)?;
                wr_u32(buf, table + i * 8, so)?;
                walk_resolve(buf, vo, depth + 1, place)?;
            }
        }
        TAG_NULL | TAG_FALSE | TAG_TRUE | TAG_LONG | TAG_DOUBLE => {}
        _ => return None,
    }
    Some(())
}

// ---------------------------------------------------------------------------
// Reader (extension side)
// ---------------------------------------------------------------------------

/// A validated image. Construction checks the header and geometry; the accessors
/// bounds-check every offset, so a damaged image yields `None` (the caller then
/// parses the raw bytes) instead of reading outside the mapping.
///
/// `seg` is the whole segment mapping, because that is where the strings live
/// (see the module docs). It is a superset of `bytes`, and both are borrowed
/// from the same pinned mapping.
pub struct Image<'a> {
    pub bytes: &'a [u8],
    pub seg: &'a [u8],
    pub root: u32,
}

/// One decoded value node.
pub enum Node<'a> {
    Null,
    Bool(bool),
    Long(i64),
    Double(f64),
    /// SEGMENT offset of the `zend_string` header, plus its bytes for the copy
    /// path.
    Str { off: u32, bytes: &'a [u8] },
    List { count: u32, table: u32 },
    Map { count: u32, table: u32 },
}

fn rd_u16(b: &[u8], off: usize) -> Option<u16> {
    b.get(off..off + 2)
        .map(|s| u16::from_le_bytes([s[0], s[1]]))
}
fn rd_u32(b: &[u8], off: usize) -> Option<u32> {
    b.get(off..off + 4)
        .map(|s| u32::from_le_bytes([s[0], s[1], s[2], s[3]]))
}
fn rd_u64(b: &[u8], off: usize) -> Option<u64> {
    b.get(off..off + 8).map(|s| {
        u64::from_le_bytes([s[0], s[1], s[2], s[3], s[4], s[5], s[6], s[7]])
    })
}

impl<'a> Image<'a> {
    /// Validate the header. Returns None for anything unrecognised — a foreign,
    /// truncated or still-unresolved image must degrade to the parse path,
    /// never be trusted.
    #[must_use]
    pub fn open(bytes: &'a [u8], seg: &'a [u8]) -> Option<Self> {
        if bytes.len() < HEADER_SIZE {
            return None;
        }
        if rd_u32(bytes, 0)? != MAGIC || rd_u16(bytes, 4)? != VERSION {
            return None;
        }
        let flags = rd_u16(bytes, 6)?;
        if flags & FLAG_ZEND_STRINGS == 0 {
            return None;
        }
        // An ID-image never reached `resolve`. Its string slots hold interner
        // IDs, which as addresses are small integers — reading one is a fault,
        // not a wrong answer. Refuse it here, once, rather than bounds-checking
        // the same mistake at every accessor.
        if flags & FLAG_STRING_IDS != 0 {
            return None;
        }
        let root = rd_u32(bytes, 12)?;
        let img = Image { bytes, seg, root };
        // Cheap sanity check that the root is addressable; the recursive walk
        // bounds-checks everything else as it goes.
        img.node(root)?;
        Some(img)
    }

    /// Decode the value node at `off`.
    #[must_use]
    pub fn node(&self, off: u32) -> Option<Node<'a>> {
        let o = off as usize;
        if o % 8 != 0 || o.checked_add(NODE_SIZE)? > self.bytes.len() {
            return None;
        }
        let tag = *self.bytes.get(o)?;
        let count = rd_u32(self.bytes, o + 4)?;
        let payload = rd_u64(self.bytes, o + 8)?;
        Some(match tag {
            TAG_NULL => Node::Null,
            TAG_FALSE => Node::Bool(false),
            TAG_TRUE => Node::Bool(true),
            TAG_LONG => Node::Long(payload as i64),
            TAG_DOUBLE => Node::Double(f64::from_bits(payload)),
            TAG_STR => {
                let so = u32::try_from(payload).ok()?;
                let bytes = self.string_bytes(so)?;
                Node::Str { off: so, bytes }
            }
            TAG_LIST | TAG_MAP => {
                if count as usize > MAX_COUNT {
                    return None;
                }
                let table = u32::try_from(payload).ok()?;
                let per = if tag == TAG_LIST { 4usize } else { 8usize };
                let span = (count as usize).checked_mul(per)?;
                if (table as usize).checked_add(span)? > self.bytes.len() {
                    return None;
                }
                if tag == TAG_LIST {
                    Node::List { count, table }
                } else {
                    Node::Map { count, table }
                }
            }
            _ => return None,
        })
    }

    /// Bytes of the `zend_string` at segment offset `off` (without the header
    /// or the NUL).
    #[must_use]
    pub fn string_bytes(&self, off: u32) -> Option<&'a [u8]> {
        let o = off as usize;
        if o % 8 != 0 {
            return None;
        }
        let len = php_abi::zend_string_len(self.seg, o)?;
        self.seg.get(o + php_abi::ZS_OFF_VAL..o + php_abi::ZS_OFF_VAL + len)
    }

    /// Offset of the nth element of a list.
    #[must_use]
    pub fn list_elem(&self, table: u32, i: u32) -> Option<u32> {
        rd_u32(self.bytes, table as usize + i as usize * 4)
    }

    /// (key string offset, value node offset) of the nth pair of a map.
    #[must_use]
    pub fn map_pair(&self, table: u32, i: u32) -> Option<(u32, u32)> {
        let base = table as usize + i as usize * 8;
        Some((rd_u32(self.bytes, base)?, rd_u32(self.bytes, base + 4)?))
    }

    /// True when the root is a JSON object — the only shape `getObject()` can
    /// serve directly without applying PHP's `(object)` cast rules.
    #[must_use]
    pub fn root_is_map(&self) -> bool {
        matches!(self.node(self.root), Some(Node::Map { .. }))
    }

    /// Walk the image back into a `serde_json::Value`.
    ///
    /// The read path never needs this — it materializes zvals directly — but
    /// the daemon-free consumers do: `update()`'s read-modify-write and
    /// `find()`'s `order_by: json:` both want a `Value` and, since the record
    /// no longer carries the raw bytes, the image is the only in-memory source.
    /// Depth-bounded like every other walk here.
    #[must_use]
    pub fn to_value(&self, off: u32, depth: u32) -> Option<Value> {
        if depth > MAX_DEPTH {
            return None;
        }
        Some(match self.node(off)? {
            Node::Null => Value::Null,
            Node::Bool(b) => Value::Bool(b),
            Node::Long(i) => Value::from(i),
            Node::Double(d) => Value::from(d),
            Node::Str { bytes, .. } => Value::String(String::from_utf8(bytes.to_vec()).ok()?),
            Node::List { count, table } => {
                let mut out = Vec::with_capacity(count as usize);
                for i in 0..count {
                    out.push(self.to_value(self.list_elem(table, i)?, depth + 1)?);
                }
                Value::Array(out)
            }
            Node::Map { count, table } => {
                let mut m = serde_json::Map::new();
                for i in 0..count {
                    let (k, v) = self.map_pair(table, i)?;
                    let key = String::from_utf8(self.string_bytes(k)?.to_vec()).ok()?;
                    m.insert(key, self.to_value(v, depth + 1)?);
                }
                Value::Object(m)
            }
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    /// Encode + resolve into a standalone buffer pair, mimicking what a segment
    /// does: strings are appended to `seg` (which starts with a dummy header so
    /// offset 0 stays an invalid sentinel) and referenced from there.
    fn build(v: &Value) -> (Vec<u8>, Vec<u8>, Interner) {
        let mut interner = Interner::new();
        let img = encode(v, &mut interner);
        assert!(!img.is_empty(), "encode produced nothing for {v}");
        let mut seg = vec![0u8; 64];
        let mut placed: HashMap<u32, u32> = HashMap::new();
        let resolved = resolve(&img, |id| {
            if let Some(o) = placed.get(&id) {
                return Some(*o);
            }
            let s = interner.get(id)?;
            let o = php_abi::push_zend_string(&mut seg, s.as_bytes());
            placed.insert(id, o);
            Some(o)
        })
        .expect("resolve");
        (resolved, seg, interner)
    }

    fn read_back(img: &Image, off: u32) -> Value {
        img.to_value(off, 0).expect("valid image")
    }

    #[test]
    fn round_trips_every_json_shape() {
        let cases = vec![
            json!(null),
            json!(true),
            json!(false),
            json!(0),
            json!(-1),
            json!(i64::MAX),
            json!(i64::MIN),
            json!(1.5),
            json!(-0.0),
            json!(""),
            json!("hello"),
            json!("café 😀"),
            json!([]),
            json!({}),
            json!([1, 2, 3]),
            json!({"a": 1, "b": "two"}),
            json!({"permissions": {"node_view": "anonymous", "node_edit": "admin"}}),
            json!({"xs": [{"k": 1}, {"k": 2}]}),
            json!({"deep": {"a": {"b": {"c": [1, {"d": null}]}}}}),
            json!({"": "empty key", "0": "zero", "-3": "neg"}),
        ];
        for v in cases {
            let (buf, seg, _i) = build(&v);
            let img = Image::open(&buf, &seg).expect("header validates");
            assert_eq!(read_back(&img, img.root), v, "round trip of {v}");
        }
    }

    #[test]
    fn strings_are_shared_across_documents() {
        // The whole point of the segment-wide table: two documents that use the
        // same key and value resolve to the SAME segment offsets, and the
        // second document adds nothing to the arena for them.
        let mut interner = Interner::new();
        let a = encode(&json!({"k": "v", "other": 1}), &mut interner);
        let b = encode(&json!({"k": "v", "more": 2}), &mut interner);
        let mut seg = vec![0u8; 64];
        let mut placed: HashMap<u32, u32> = HashMap::new();

        fn place_into(
            seg: &mut Vec<u8>,
            placed: &mut HashMap<u32, u32>,
            interner: &Interner,
            id: u32,
        ) -> Option<u32> {
            if let Some(o) = placed.get(&id) {
                return Some(*o);
            }
            let o = php_abi::push_zend_string(seg, interner.get(id)?.as_bytes());
            placed.insert(id, o);
            Some(o)
        }

        let ra = resolve(&a, |id| place_into(&mut seg, &mut placed, &interner, id)).unwrap();
        let before = seg.len();
        let rb = resolve(&b, |id| place_into(&mut seg, &mut placed, &interner, id)).unwrap();
        // Only "more" is new; "k" and "v" were placed by document A.
        assert!(
            seg.len() - before < 40,
            "second document re-emitted shared strings ({} B)",
            seg.len() - before
        );

        let ia = Image::open(&ra, &seg).unwrap();
        let ib = Image::open(&rb, &seg).unwrap();
        let (ka, va) = match ia.node(ia.root).unwrap() {
            Node::Map { table, .. } => ia.map_pair(table, 0).unwrap(),
            _ => panic!("root must be a map"),
        };
        let (kb, vb) = match ib.node(ib.root).unwrap() {
            Node::Map { table, .. } => ib.map_pair(table, 0).unwrap(),
            _ => panic!("root must be a map"),
        };
        assert_eq!(ka, kb, "shared key must resolve to one segment offset");
        let so = |img: &Image, off: u32| match img.node(off).unwrap() {
            Node::Str { off, .. } => off,
            _ => panic!("expected a string"),
        };
        assert_eq!(so(&ia, va), so(&ib, vb), "shared value must share an offset");
        // ...and the bytes are in the segment, not in either image.
        assert!(!ra.windows(1).any(|w| w == b"v"), "image must not carry string bytes");
    }

    #[test]
    fn unresolved_images_are_refused() {
        let mut interner = Interner::new();
        let img = encode(&json!({"a": "b"}), &mut interner);
        let seg = vec![0u8; 64];
        assert!(
            Image::open(&img, &seg).is_none(),
            "an ID-image must never open: its string slots are not addresses"
        );
        // ...and it cannot be resolved twice either.
        let mut s2 = vec![0u8; 64];
        let r = resolve(&img, |id| {
            Some(php_abi::push_zend_string(&mut s2, interner.get(id)?.as_bytes()))
        })
        .unwrap();
        assert!(resolve(&r, |_| Some(8)).is_none(), "double resolve must fail");
    }

    #[test]
    fn resolve_fails_whole_image_when_placement_fails() {
        let mut interner = Interner::new();
        let img = encode(&json!({"a": "b", "c": "d"}), &mut interner);
        // A placer that runs out of room after the first string must abort the
        // whole image, not publish one half-rewritten.
        let mut n = 0;
        assert!(resolve(&img, |_| {
            n += 1;
            if n > 1 { None } else { Some(8) }
        })
        .is_none());
    }

    #[test]
    fn rejects_foreign_or_truncated_images() {
        let (good, seg, _i) = build(&json!({"a": 1, "b": [1, 2, {"c": "d"}]}));
        assert!(Image::open(&[], &seg).is_none());
        assert!(Image::open(&[0u8; 8], &seg).is_none());
        let mut bad = good.clone();
        bad[0] ^= 0xff; // corrupt magic
        assert!(Image::open(&bad, &seg).is_none());
        // A payload truncated anywhere must be rejected or safely walkable,
        // never a panic or an out-of-bounds read.
        for cut in 0..good.len() {
            if let Some(img) = Image::open(&good[..cut], &seg) {
                let _ = img.node(img.root);
                let _ = img.root_is_map();
                let _ = img.to_value(img.root, 0);
            }
        }
        // Same for a truncated segment: the strings are out of bounds now.
        for cut in 0..seg.len() {
            if let Some(img) = Image::open(&good, &seg[..cut]) {
                let _ = img.to_value(img.root, 0);
            }
        }
    }

    #[test]
    fn refuses_documents_deeper_than_php_allows() {
        let mut v = json!(1);
        for _ in 0..(MAX_DEPTH + 10) {
            v = Value::Array(vec![v]);
        }
        let mut interner = Interner::new();
        assert!(
            encode(&v, &mut interner).is_empty(),
            "over-deep document must not be imaged"
        );
    }

    #[test]
    fn string_offsets_are_eight_byte_aligned() {
        let (buf, seg, _i) = build(&json!({"aa": "b", "ccc": "dddd", "e": ""}));
        let img = Image::open(&buf, &seg).unwrap();
        let Node::Map { count, table } = img.node(img.root).unwrap() else {
            panic!("root must be a map");
        };
        for i in 0..count {
            let (k, _) = img.map_pair(table, i).unwrap();
            assert_eq!(k % 8, 0, "zend_string must be 8-aligned");
        }
    }

    #[test]
    fn interner_ids_are_stable_and_dedup() {
        let mut i = Interner::new();
        let a = i.intern("title");
        let b = i.intern("body");
        assert_eq!(i.intern("title"), a);
        assert_ne!(a, b);
        assert_eq!(i.get(a), Some("title"));
        assert_eq!(i.len(), 2);
        assert_eq!(i.get(999), None);
    }
}
