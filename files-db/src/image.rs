//! Pre-decoded document image: a JSON document flattened into a form a PHP
//! worker can turn into zvals in one linear pass — no tokenizing, no number
//! parsing, no UTF-8 revalidation, no string allocation.
//!
//! Written by `qdbd` (which already parses every document to detect corruption,
//! see `model::load_docs`, so the encode is nearly free) and read by the
//! extension straight out of its read-only mapping.
//!
//! **No pointers anywhere.** Every reference is an offset from the start of the
//! image, so the same bytes are valid at whatever address each worker happens
//! to map the segment at. String entries are ready-made `zend_string`s
//! (`php_abi::push_zend_string`), which is what lets a zval point directly into
//! shared memory instead of copying.
//!
//! PHP-free, like `php_abi`: `qdbd` links this without linking PHP.

use serde_json::Value;

use crate::php_abi;

pub const MAGIC: u32 = 0x4942_4451; // "QDBI"
pub const VERSION: u16 = 1;
pub const HEADER_SIZE: usize = 16;

/// Bit 0: string entries are ready-made `zend_string` structs.
pub const FLAG_ZEND_STRINGS: u16 = 1;

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
///   8  payload    u64   long: i64 | double: f64 bits | str: str_off
///                       list: elems_off | map: pairs_off
///
/// list elements: count x u32 node_off
/// map pairs:     count x { u32 key_str_off, u32 val_node_off }
/// string entry:  a zend_string (see php_abi::push_zend_string)
/// ```
///
/// Strings are deduplicated within the document, which collapses the repeated
/// keys of same-shaped objects (file lists, `permissions`) that Quanta
/// documents are full of.
pub struct Encoder {
    buf: Vec<u8>,
    strings: std::collections::HashMap<String, u32>,
    node_count: u32,
}

impl Encoder {
    fn new() -> Self {
        let mut buf = Vec::with_capacity(256);
        buf.resize(HEADER_SIZE, 0);
        Self {
            buf,
            strings: std::collections::HashMap::new(),
            node_count: 0,
        }
    }

    fn intern(&mut self, s: &str) -> u32 {
        if let Some(off) = self.strings.get(s) {
            return *off;
        }
        let off = php_abi::push_zend_string(&mut self.buf, s.as_bytes());
        self.strings.insert(s.to_string(), off);
        off
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
                let so = self.intern(s);
                self.write_node(off, TAG_STR, 0, u64::from(so));
            }
            Value::Array(items) => {
                if items.len() > MAX_COUNT {
                    return Err(ImageError::TooLarge);
                }
                // Children first, then the offset table: an element's own
                // encoding may append strings, so the table cannot be
                // contiguous with the recursion.
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
                    let ko = self.intern(k);
                    let vo = self.encode_value(val, depth + 1)?;
                    pairs.push((ko, vo));
                }
                while self.buf.len() % 4 != 0 {
                    self.buf.push(0);
                }
                let table = self.buf.len() as u32;
                for (ko, vo) in &pairs {
                    self.buf.extend_from_slice(&ko.to_le_bytes());
                    self.buf.extend_from_slice(&vo.to_le_bytes());
                }
                self.write_node(off, TAG_MAP, map.len() as u32, u64::from(table));
            }
        }
        Ok(off)
    }
}

/// Encode one decoded document. Returns an empty vec if the document cannot be
/// imaged (too deep, too large) — callers treat that as "no image", never as an
/// error: the raw bytes are still in the record and the reader falls back to
/// parsing them.
#[must_use]
pub fn encode(v: &Value) -> Vec<u8> {
    let mut e = Encoder::new();
    let Ok(root) = e.encode_value(v, 0) else {
        return Vec::new();
    };
    let node_count = e.node_count;
    let buf = &mut e.buf;
    buf[0..4].copy_from_slice(&MAGIC.to_le_bytes());
    buf[4..6].copy_from_slice(&VERSION.to_le_bytes());
    buf[6..8].copy_from_slice(&FLAG_ZEND_STRINGS.to_le_bytes());
    buf[8..12].copy_from_slice(&node_count.to_le_bytes());
    buf[12..16].copy_from_slice(&root.to_le_bytes());
    e.buf
}

// ---------------------------------------------------------------------------
// Reader (extension side)
// ---------------------------------------------------------------------------

/// A validated image. Construction checks the header and geometry; the accessors
/// bounds-check every offset, so a damaged image yields `None` (the caller then
/// parses the raw bytes) instead of reading outside the mapping.
pub struct Image<'a> {
    pub bytes: &'a [u8],
    pub root: u32,
}

/// One decoded value node.
pub enum Node<'a> {
    Null,
    Bool(bool),
    Long(i64),
    Double(f64),
    /// Offset of the `zend_string` header, plus its bytes for the copy path.
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
    /// Validate the header. Returns None for anything unrecognised — a foreign
    /// or truncated image must degrade to the parse path, never be trusted.
    #[must_use]
    pub fn open(bytes: &'a [u8]) -> Option<Self> {
        if bytes.len() < HEADER_SIZE {
            return None;
        }
        if rd_u32(bytes, 0)? != MAGIC || rd_u16(bytes, 4)? != VERSION {
            return None;
        }
        if rd_u16(bytes, 6)? & FLAG_ZEND_STRINGS == 0 {
            return None;
        }
        let root = rd_u32(bytes, 12)?;
        let img = Image { bytes, root };
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

    /// Bytes of the `zend_string` at `off` (without the header or the NUL).
    #[must_use]
    pub fn string_bytes(&self, off: u32) -> Option<&'a [u8]> {
        let o = off as usize;
        if o % 8 != 0 {
            return None;
        }
        let len = php_abi::zend_string_len(self.bytes, o)?;
        self.bytes.get(o + php_abi::ZS_OFF_VAL..o + php_abi::ZS_OFF_VAL + len)
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
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn roundtrip(v: &Value) -> Vec<u8> {
        let img = encode(v);
        assert!(!img.is_empty(), "encode produced nothing for {v}");
        img
    }

    /// Walk an image back into a serde Value so encode/decode can be compared
    /// against the input directly.
    fn read_back(img: &Image, off: u32) -> Value {
        match img.node(off).expect("valid node") {
            Node::Null => Value::Null,
            Node::Bool(b) => Value::Bool(b),
            Node::Long(i) => Value::from(i),
            Node::Double(d) => Value::from(d),
            Node::Str { bytes, .. } => Value::String(String::from_utf8(bytes.to_vec()).unwrap()),
            Node::List { count, table } => Value::Array(
                (0..count)
                    .map(|i| read_back(img, img.list_elem(table, i).unwrap()))
                    .collect(),
            ),
            Node::Map { count, table } => {
                let mut m = serde_json::Map::new();
                for i in 0..count {
                    let (k, v) = img.map_pair(table, i).unwrap();
                    let key = String::from_utf8(img.string_bytes(k).unwrap().to_vec()).unwrap();
                    m.insert(key, read_back(img, v));
                }
                Value::Object(m)
            }
        }
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
            let buf = roundtrip(&v);
            let img = Image::open(&buf).expect("header validates");
            assert_eq!(read_back(&img, img.root), v, "round trip of {v}");
        }
    }

    #[test]
    fn strings_are_deduplicated_within_a_document() {
        let v = json!([{"k": "v"}, {"k": "v"}, {"k": "v"}, {"k": "v"}]);
        let buf = encode(&v);
        // "k" and "v" must each be stored once, not four times.
        let occurrences = buf.windows(1).filter(|w| w[0] == b'k').count();
        assert!(occurrences <= 2, "key bytes repeated {occurrences} times");
    }

    #[test]
    fn rejects_foreign_or_truncated_images() {
        assert!(Image::open(&[]).is_none());
        assert!(Image::open(&[0u8; 8]).is_none());
        let mut buf = encode(&json!({"a": 1}));
        buf[0] ^= 0xff; // corrupt magic
        assert!(Image::open(&buf).is_none());
        // A payload truncated anywhere must be rejected or safely walkable,
        // never a panic or an out-of-bounds read.
        let good = encode(&json!({"a": 1, "b": [1, 2, {"c": "d"}]}));
        for cut in 0..good.len() {
            if let Some(img) = Image::open(&good[..cut]) {
                // Walking a surviving header must still be bounds-checked.
                let _ = img.node(img.root);
                let _ = img.root_is_map();
            }
        }
    }

    #[test]
    fn refuses_documents_deeper_than_php_allows() {
        let mut v = json!(1);
        for _ in 0..(MAX_DEPTH + 10) {
            v = Value::Array(vec![v]);
        }
        assert!(encode(&v).is_empty(), "over-deep document must not be imaged");
    }

    #[test]
    fn string_offsets_are_eight_byte_aligned() {
        let buf = encode(&json!({"aa": "b", "ccc": "dddd", "e": ""}));
        let img = Image::open(&buf).unwrap();
        let Node::Map { count, table } = img.node(img.root).unwrap() else {
            panic!("root must be a map");
        };
        for i in 0..count {
            let (k, _) = img.map_pair(table, i).unwrap();
            assert_eq!(k % 8, 0, "zend_string must be 8-aligned");
        }
    }
}
