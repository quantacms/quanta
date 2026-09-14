//! Shared-memory data segment: the whole node tree (paths, fathers, children,
//! links and raw JSON documents) lives in one mmap'd file written by the `qdbd`
//! daemon and mapped read-only by every PHP worker. A read is a hash probe plus
//! bounds-checked byte decoding — no filesystem access, no SQLite.
//!
//! Concurrency model (single writer, many readers, no locks):
//!   * records are IMMUTABLE once published — the daemon claims fresh arena
//!     space, writes the bytes, then publishes with one Release store into the
//!     slot directory; a reader's Acquire load therefore always dereferences
//!     fully-written bytes;
//!   * slots only ever transition between published offsets (empty -> rec,
//!     recA -> recB, rec -> tombstone), never back to garbage;
//!   * a segment file is never truncated or recycled: growth/compaction writes
//!     a NEW file (`data.<epoch+1>.shm`) and flips the epoch in the metrics
//!     arena — an unlinked predecessor stays valid for readers still mapping it;
//!   * every reader access is bounds-checked against the fixed `seg_size`, and
//!     records are verified by full hash + name comparison, so even a corrupt
//!     slot value can only produce a miss, never a fault.
//!
//! PHP-free (std + libc only) so `qdbd`/`qdbstat` reuse it via `#[path]` include.
#![allow(dead_code)]

use std::ffi::CString;
use std::io;
use std::os::unix::ffi::OsStrExt;
use std::path::{Path, PathBuf};
use std::ptr;
use std::sync::atomic::{AtomicU64, Ordering};

/// "QDBDAT1\0" little-endian.
const MAGIC: u64 = 0x0031_5441_4442_4451;
/// Bumped to 2 when the per-language pre-decoded image was added, and to 3 when
/// image strings moved out of the per-document image into a segment-wide table
/// (`image.rs`). A reader built against a different layout rejects the segment
/// in `SegmentReader::open` and degrades to fallback mode, so a daemon/extension
/// version skew is safe — rollout is a restart, never a migration.
pub const LAYOUT_VERSION: u32 = 3;
/// Fixed header block; slot directory starts right after it.
pub const HEADER_SIZE: u64 = 4096;

const REL: Ordering = Ordering::Relaxed;

/// Record flag: the name is known-absent (deleted); probing must not stop early
/// (linear-probe chains stay intact), but a lookup answers "definitively gone".
pub const REC_TOMBSTONE: u16 = 1;
/// Per-language flag: the doc file exists but does not parse. Readers must
/// surface CORRUPT_JSON (contract §7), not "no document".
pub const LANG_CORRUPT: u16 = 1;
/// Per-language flag: a pre-decoded image (`image.rs`) accompanies the doc.
pub const LANG_HAS_IMAGE: u16 = 2;
/// Per-language flag: the record carries the document's exact bytes.
///
/// Cleared from layout v3 on. The image is a complete representation of the
/// decoded document, so storing the JSON next to it was storing the same
/// content twice — 39.7 MB of hili's production segment, and the same again in
/// the daemon's own heap, which held every document's bytes for the life of the
/// model just to re-encode them on republish.
///
/// What still needs the exact bytes reads the file: `getRaw()` (whose contract
/// IS byte fidelity, and whose callers are integrity/doctor/migration code, not
/// the render path) and any document too large or too deep to image. Both are
/// off the hot path, and the files were always the source of truth.
pub const LANG_HAS_RAW: u16 = 4;

/// Fixed-size record header preceding the variable section (see `encode_record`).
pub const REC_FIXED: usize = 44;
/// Fixed part of one language-table entry (lang/doc bytes follow all entries).
pub const LANG_FIXED: usize = 32;

// ---------------------------------------------------------------------------
// Header
// ---------------------------------------------------------------------------

/// Lives at offset 0 of the segment file. Only the daemon writes it (except
/// nothing: readers are strictly read-only). Fields after `daemon_pid` are
/// reserved — append only, never reorder (same rule as the metrics arena).
#[repr(C)]
pub struct SegHeader {
    pub magic: AtomicU64,
    pub layout_version: u32,
    pub _pad0: u32,
    pub epoch: u64,
    pub seg_size: u64,
    pub slot_count: u64,
    pub slots_off: u64,
    pub arena_off: u64,
    pub arena_next: AtomicU64,
    /// 0 while the initial fill is in progress, 1 (Release) once every record
    /// is published. Readers Acquire-check it before trusting the directory.
    pub ready: AtomicU64,
    pub node_count: AtomicU64,
    pub link_count: AtomicU64,
    pub tombstone_count: AtomicU64,
    /// Bytes of superseded + tombstone records (compaction trigger input).
    pub dead_bytes: AtomicU64,
    /// Live raw-JSON payload bytes (diagnostics, qdbstat).
    pub doc_bytes: AtomicU64,
    pub root_hash: u64,
    pub daemon_pid: u64,
    /// Live pre-decoded image bytes (diagnostics, qdbstat). Appended after
    /// `daemon_pid` per the append-only rule above.
    pub img_bytes: AtomicU64,
    /// Bytes of the segment-wide string table (v3+). Separate from `img_bytes`
    /// because the whole point of the table is that it does NOT scale with the
    /// number of documents — reporting them merged would hide that.
    pub str_bytes: AtomicU64,
    /// Distinct strings placed in this segment (v3+).
    pub str_count: AtomicU64,
    /// Raw JSON bytes actually RESIDENT in the segment (v3+). `doc_bytes` above
    /// is the documents' size on disk, which the daemon knows either way; this
    /// is what the segment pays for them, and it is 0 once `LANG_HAS_RAW` stops
    /// being set. Two fields because the interesting number is the difference.
    pub raw_bytes: AtomicU64,
}

const _: () = assert!(std::mem::size_of::<SegHeader>() <= HEADER_SIZE as usize);

/// FNV-1a 64 over the node name. Dependency-free and stable across the
/// `.so`/bin boundary (unlike `DefaultHasher`, this is *specified*, so a
/// segment written by one build is always readable by another).
pub fn fnv1a(bytes: &[u8]) -> u64 {
    let mut h: u64 = 0xcbf2_9ce4_8422_2325;
    for b in bytes {
        h ^= *b as u64;
        h = h.wrapping_mul(0x0000_0100_0000_01b3);
    }
    h
}

#[inline]
fn slot_pack(hash: u64, off: u64) -> u64 {
    (hash & 0xffff_0000_0000_0000) | (off & 0x0000_ffff_ffff_ffff)
}

#[inline]
fn slot_tag(packed: u64) -> u64 {
    packed & 0xffff_0000_0000_0000
}

#[inline]
fn slot_off(packed: u64) -> u64 {
    packed & 0x0000_ffff_ffff_ffff
}

pub fn segment_file(dir: &Path, epoch: u64) -> PathBuf {
    dir.join(format!("data.{epoch}.shm"))
}

fn mmap_fd(fd: libc::c_int, len: usize, writable: bool) -> io::Result<*mut u8> {
    let prot = if writable {
        libc::PROT_READ | libc::PROT_WRITE
    } else {
        libc::PROT_READ
    };
    let addr = unsafe { libc::mmap(ptr::null_mut(), len, prot, libc::MAP_SHARED, fd, 0) };
    if addr == libc::MAP_FAILED {
        return Err(io::Error::last_os_error());
    }
    Ok(addr as *mut u8)
}

// ---------------------------------------------------------------------------
// Record encoding (byte-level; decoded via bounds-checked slices, never casts)
// ---------------------------------------------------------------------------

/// One language document carried by a record.
pub struct LangDoc<'a> {
    pub lang: &'a str,
    /// Raw JSON bytes; empty when `corrupt` (the file exists but won't parse).
    pub doc: &'a [u8],
    pub corrupt: bool,
    pub doc_mtime: i64,
    pub doc_size: i64,
    /// Pre-decoded image (`image::encode`), or empty when none was built
    /// (images disabled, document too large, or it failed to encode).
    pub image: &'a [u8],
}

/// Borrowed view of everything a record stores; the daemon's model produces it.
pub struct RecordInput<'a> {
    pub name: &'a str,
    /// Root-relative node dir path ("" only for a hypothetical root record).
    pub rel_path: &'a str,
    pub father: Option<&'a str>,
    pub generation: u64,
    pub mtime: i64,
    /// Sorted (name, is_link) pairs mirroring `children_impl` enumeration,
    /// including `_`-hidden entries (the reader filters).
    pub children: &'a [(String, bool)],
    /// Sorted container names holding a symlink to this node.
    pub inlinks: &'a [String],
    pub langs: &'a [LangDoc<'a>],
}

fn put_u16(buf: &mut Vec<u8>, v: u16) {
    buf.extend_from_slice(&v.to_le_bytes());
}
fn put_u32(buf: &mut Vec<u8>, v: u32) {
    buf.extend_from_slice(&v.to_le_bytes());
}
fn put_u64(buf: &mut Vec<u8>, v: u64) {
    buf.extend_from_slice(&v.to_le_bytes());
}
fn put_i64(buf: &mut Vec<u8>, v: i64) {
    buf.extend_from_slice(&v.to_le_bytes());
}

/// Serialize a full record (fixed header + variable section, zero-padded to 8).
/// Layout:
///   0  rec_len       u32   total bytes incl. padding
///   4  flags         u16   REC_*
///   6  lang_count    u16
///   8  generation    u64
///   16 name_hash     u64
///   24 mtime         i64
///   32 name_len      u16
///   34 path_len      u16
///   36 father_len    u16   0 = root-level node
///   38 child_count   u16
///   40 inlink_count  u16
///   42 _pad          u16
///   44 name | rel_path | father
///      children: child_count x (u16 [bit15=is_link | len]) then name bytes
///      inlinks:  inlink_count x (u16 len) then name bytes
///      langs:    lang_count x {u16 lang_len, u16 flags, u32 doc_len,
///                              i64 doc_mtime, i64 doc_size,
///                              u32 img_off, u32 img_len}
///                then per lang: lang bytes | doc bytes
///                then, 8-aligned: the pre-decoded images
///
/// `img_off` is an offset from the START OF THIS RECORD (0 = no image), not a
/// position in the doc byte stream. Images are placed last and individually
/// 8-aligned so that — because records themselves start at 8-aligned arena
/// offsets — every `zend_string` inside an image is 8-aligned in the mapping,
/// which is what lets the extension point PHP zvals straight at it.
pub fn encode_record(r: &RecordInput, tombstone: bool) -> Vec<u8> {
    let mut buf = Vec::with_capacity(256);
    let father = r.father.unwrap_or("");
    put_u32(&mut buf, 0); // rec_len patched below
    put_u16(&mut buf, if tombstone { REC_TOMBSTONE } else { 0 });
    put_u16(&mut buf, r.langs.len() as u16);
    put_u64(&mut buf, r.generation);
    put_u64(&mut buf, fnv1a(r.name.as_bytes()));
    put_i64(&mut buf, r.mtime);
    put_u16(&mut buf, r.name.len() as u16);
    put_u16(&mut buf, r.rel_path.len() as u16);
    put_u16(&mut buf, father.len() as u16);
    put_u16(&mut buf, r.children.len() as u16);
    put_u16(&mut buf, r.inlinks.len() as u16);
    put_u16(&mut buf, 0);
    debug_assert_eq!(buf.len(), REC_FIXED);

    buf.extend_from_slice(r.name.as_bytes());
    buf.extend_from_slice(r.rel_path.as_bytes());
    buf.extend_from_slice(father.as_bytes());
    for (name, is_link) in r.children {
        let mut v = name.len() as u16;
        if *is_link {
            v |= 0x8000;
        }
        put_u16(&mut buf, v);
    }
    for (name, _) in r.children {
        buf.extend_from_slice(name.as_bytes());
    }
    for name in r.inlinks {
        put_u16(&mut buf, name.len() as u16);
    }
    for name in r.inlinks {
        buf.extend_from_slice(name.as_bytes());
    }
    let lang_tab = buf.len();
    for l in r.langs {
        put_u16(&mut buf, l.lang.len() as u16);
        let mut flags = if l.corrupt { LANG_CORRUPT } else { 0 };
        if !l.image.is_empty() {
            flags |= LANG_HAS_IMAGE;
        }
        // The daemon decides whether to ship the bytes by supplying them or
        // not; the flag records that decision so a reader never has to infer
        // "not stored" from an empty slice (which a 0-byte file would also
        // produce).
        if !l.doc.is_empty() {
            flags |= LANG_HAS_RAW;
        }
        put_u16(&mut buf, flags);
        put_u32(&mut buf, l.doc.len() as u32);
        put_i64(&mut buf, l.doc_mtime);
        put_i64(&mut buf, l.doc_size);
        put_u32(&mut buf, 0); // img_off, patched below
        put_u32(&mut buf, l.image.len() as u32);
    }
    for l in r.langs {
        buf.extend_from_slice(l.lang.as_bytes());
        buf.extend_from_slice(l.doc);
    }
    // Images last, each 8-aligned within the record (see the layout note).
    for (i, l) in r.langs.iter().enumerate() {
        if l.image.is_empty() {
            continue;
        }
        while buf.len() % 8 != 0 {
            buf.push(0);
        }
        let img_off = buf.len() as u32;
        let e = lang_tab + i * LANG_FIXED;
        buf[e + 24..e + 28].copy_from_slice(&img_off.to_le_bytes());
        buf.extend_from_slice(l.image);
    }
    while buf.len() % 8 != 0 {
        buf.push(0);
    }
    let len = buf.len() as u32;
    buf[0..4].copy_from_slice(&len.to_le_bytes());
    buf
}

/// A minimal tombstone (name + hash only) marking a name definitively absent.
pub fn encode_tombstone(name: &str, generation: u64) -> Vec<u8> {
    let input = RecordInput {
        name,
        rel_path: "",
        father: None,
        generation,
        mtime: 0,
        children: &[],
        inlinks: &[],
        langs: &[],
    };
    encode_record(&input, true)
}

// ---------------------------------------------------------------------------
// Record view (reader side; every access bounds-checked against the slice)
// ---------------------------------------------------------------------------

#[inline]
fn rd_u16(b: &[u8], off: usize) -> u16 {
    u16::from_le_bytes([b[off], b[off + 1]])
}
#[inline]
fn rd_u32(b: &[u8], off: usize) -> u32 {
    u32::from_le_bytes([b[off], b[off + 1], b[off + 2], b[off + 3]])
}
#[inline]
fn rd_u64(b: &[u8], off: usize) -> u64 {
    u64::from_le_bytes(b[off..off + 8].try_into().expect("8 bytes"))
}
#[inline]
fn rd_i64(b: &[u8], off: usize) -> i64 {
    i64::from_le_bytes(b[off..off + 8].try_into().expect("8 bytes"))
}

/// Decoded (but not copied) record. `bytes` is the exact record slice; all the
/// variable-section offsets were validated by [`RecordView::parse`].
pub struct RecordView<'a> {
    bytes: &'a [u8],
    pub flags: u16,
    pub generation: u64,
    pub name_hash: u64,
    pub mtime: i64,
    name_len: usize,
    path_len: usize,
    father_len: usize,
    child_count: usize,
    inlink_count: usize,
    lang_count: usize,
}

/// What a record can say about one language's raw JSON.
#[derive(Debug, PartialEq)]
pub enum DocBytes<'a> {
    /// The record carries the exact bytes.
    Stored(&'a [u8]),
    /// The document exists, but only its image is in shared memory. A caller
    /// that needs a `Value` should walk the image; one that needs the exact
    /// bytes must read the file.
    NotStored,
    /// This node has no document in this language.
    Absent,
}

impl<'a> DocBytes<'a> {
    /// The bytes, when the record has them. `None` covers both `NotStored` and
    /// `Absent`, so use it only where those two mean the same thing.
    #[must_use]
    pub fn stored(self) -> Option<&'a [u8]> {
        match self {
            DocBytes::Stored(b) => Some(b),
            _ => None,
        }
    }

    /// True when the language exists on the node at all.
    #[must_use]
    pub fn exists(&self) -> bool {
        !matches!(self, DocBytes::Absent)
    }
}

pub struct LangView<'a> {
    pub lang: &'a str,
    pub flags: u16,
    pub doc: &'a [u8],
    pub doc_mtime: i64,
    pub doc_size: i64,
    /// Pre-decoded image bytes, empty when the record carries none.
    pub img: &'a [u8],
}

impl<'a> RecordView<'a> {
    /// Validate the whole variable-section geometry once; any inconsistency
    /// (torn slot, corrupt bytes) yields `None`, never a panic.
    pub fn parse(bytes: &'a [u8]) -> Option<RecordView<'a>> {
        if bytes.len() < REC_FIXED {
            return None;
        }
        let v = RecordView {
            bytes,
            flags: rd_u16(bytes, 4),
            generation: rd_u64(bytes, 8),
            name_hash: rd_u64(bytes, 16),
            mtime: rd_i64(bytes, 24),
            name_len: rd_u16(bytes, 32) as usize,
            path_len: rd_u16(bytes, 34) as usize,
            father_len: rd_u16(bytes, 36) as usize,
            child_count: rd_u16(bytes, 38) as usize,
            inlink_count: rd_u16(bytes, 40) as usize,
            lang_count: rd_u16(bytes, 6) as usize,
        };
        // Walk the geometry; every section must fit inside `bytes`.
        let mut off = REC_FIXED + v.name_len + v.path_len + v.father_len;
        off = off.checked_add(v.child_count * 2)?;
        if off > bytes.len() {
            return None;
        }
        for i in 0..v.child_count {
            off += (rd_u16(bytes, v.child_len_off(i)) & 0x7fff) as usize;
        }
        off = off.checked_add(v.inlink_count * 2)?;
        if off > bytes.len() {
            return None;
        }
        let inlink_tab = off - v.inlink_count * 2;
        for i in 0..v.inlink_count {
            off += rd_u16(bytes, inlink_tab + i * 2) as usize;
        }
        off = off.checked_add(v.lang_count * LANG_FIXED)?;
        if off > bytes.len() {
            return None;
        }
        let lang_tab = off - v.lang_count * LANG_FIXED;
        for i in 0..v.lang_count {
            off += rd_u16(bytes, lang_tab + i * LANG_FIXED) as usize;
            off = off.checked_add(rd_u32(bytes, lang_tab + i * LANG_FIXED + 4) as usize)?;
        }
        if off > bytes.len() {
            return None;
        }
        // Images sit after the doc payloads at record-relative offsets. Verify
        // each one is inside the record and 8-aligned — the extension hands
        // addresses inside these bytes to the Zend engine, so an unaligned or
        // out-of-bounds image must be rejected here, before any reader sees it.
        for i in 0..v.lang_count {
            let e = lang_tab + i * LANG_FIXED;
            let img_off = rd_u32(bytes, e + 24) as usize;
            let img_len = rd_u32(bytes, e + 28) as usize;
            if img_len == 0 {
                continue;
            }
            if img_off % 8 != 0 || img_off < off {
                return None;
            }
            if img_off.checked_add(img_len)? > bytes.len() {
                return None;
            }
        }
        Some(v)
    }

    pub fn is_tombstone(&self) -> bool {
        self.flags & REC_TOMBSTONE != 0
    }

    /// This record's footprint in the segment arena, in bytes.
    ///
    /// The exact slice `parse` validated, so it counts everything the node
    /// costs in shared memory: the fixed header, its name, path, father,
    /// children and inlinks, and every language's raw bytes and image. Callers
    /// summing "what is filling /dev/shm" want this rather than `doc_size`,
    /// which is what the documents weigh ON DISK.
    pub fn record_bytes(&self) -> usize {
        self.bytes.len()
    }

    pub fn name(&self) -> &'a str {
        std::str::from_utf8(&self.bytes[REC_FIXED..REC_FIXED + self.name_len]).unwrap_or("")
    }

    pub fn rel_path(&self) -> &'a str {
        let s = REC_FIXED + self.name_len;
        std::str::from_utf8(&self.bytes[s..s + self.path_len]).unwrap_or("")
    }

    pub fn father(&self) -> Option<&'a str> {
        if self.father_len == 0 {
            return None;
        }
        let s = REC_FIXED + self.name_len + self.path_len;
        std::str::from_utf8(&self.bytes[s..s + self.father_len]).ok()
    }

    #[inline]
    fn child_tab_off(&self) -> usize {
        REC_FIXED + self.name_len + self.path_len + self.father_len
    }
    #[inline]
    fn child_len_off(&self, i: usize) -> usize {
        self.child_tab_off() + i * 2
    }

    /// (name, is_link) pairs in stored (sorted) order.
    pub fn children(&self) -> Vec<(&'a str, bool)> {
        let mut out = Vec::with_capacity(self.child_count);
        let tab = self.child_tab_off();
        let mut data = tab + self.child_count * 2;
        for i in 0..self.child_count {
            let v = rd_u16(self.bytes, tab + i * 2);
            let len = (v & 0x7fff) as usize;
            let name = std::str::from_utf8(&self.bytes[data..data + len]).unwrap_or("");
            out.push((name, v & 0x8000 != 0));
            data += len;
        }
        out
    }

    fn inlink_tab_off(&self) -> usize {
        let tab = self.child_tab_off();
        let mut off = tab + self.child_count * 2;
        for i in 0..self.child_count {
            off += (rd_u16(self.bytes, tab + i * 2) & 0x7fff) as usize;
        }
        off
    }

    /// Container names symlinking to this node, in stored (sorted) order.
    pub fn inlinks(&self) -> Vec<&'a str> {
        let tab = self.inlink_tab_off();
        let mut data = tab + self.inlink_count * 2;
        let mut out = Vec::with_capacity(self.inlink_count);
        for i in 0..self.inlink_count {
            let len = rd_u16(self.bytes, tab + i * 2) as usize;
            out.push(std::str::from_utf8(&self.bytes[data..data + len]).unwrap_or(""));
            data += len;
        }
        out
    }

    fn lang_tab_off(&self) -> usize {
        let tab = self.inlink_tab_off();
        let mut off = tab + self.inlink_count * 2;
        for i in 0..self.inlink_count {
            off += rd_u16(self.bytes, tab + i * 2) as usize;
        }
        off
    }

    pub fn langs(&self) -> Vec<LangView<'a>> {
        let tab = self.lang_tab_off();
        let mut data = tab + self.lang_count * LANG_FIXED;
        let mut out = Vec::with_capacity(self.lang_count);
        for i in 0..self.lang_count {
            let e = tab + i * LANG_FIXED;
            let lang_len = rd_u16(self.bytes, e) as usize;
            let flags = rd_u16(self.bytes, e + 2);
            let doc_len = rd_u32(self.bytes, e + 4) as usize;
            let lang = std::str::from_utf8(&self.bytes[data..data + lang_len]).unwrap_or("");
            data += lang_len;
            let doc = &self.bytes[data..data + doc_len];
            data += doc_len;
            // Bounds already validated in parse().
            let img_off = rd_u32(self.bytes, e + 24) as usize;
            let img_len = rd_u32(self.bytes, e + 28) as usize;
            let img = if img_len == 0 {
                &[][..]
            } else {
                &self.bytes[img_off..img_off + img_len]
            };
            out.push(LangView {
                lang,
                flags,
                doc,
                doc_mtime: rd_i64(self.bytes, e + 8),
                doc_size: rd_i64(self.bytes, e + 16),
                img,
            });
        }
        out
    }

    /// The raw JSON document for `lang`.
    ///
    /// `Err(())` = the file exists but is corrupt (caller maps to CORRUPT_JSON).
    /// Otherwise see [`DocBytes`] — note that `NotStored` is the NORMAL answer
    /// from layout v3 on, not an error: the document exists and its image is in
    /// the record, but its bytes are not.
    pub fn doc(&self, lang: &str) -> Result<DocBytes<'a>, ()> {
        for l in self.langs() {
            if l.lang == lang {
                if l.flags & LANG_CORRUPT != 0 {
                    return Err(());
                }
                return Ok(if l.flags & LANG_HAS_RAW != 0 {
                    DocBytes::Stored(l.doc)
                } else {
                    DocBytes::NotStored
                });
            }
        }
        Ok(DocBytes::Absent)
    }

    /// The pre-decoded image for `lang`, when the record carries one.
    /// Mirrors [`doc`]: `Err(())` = corrupt, `Ok(None)` = no image available
    /// (absent language, images disabled, or the document was not imaged).
    pub fn image(&self, lang: &str) -> Result<Option<&'a [u8]>, ()> {
        for l in self.langs() {
            if l.lang == lang {
                if l.flags & LANG_CORRUPT != 0 {
                    return Err(());
                }
                return Ok((l.flags & LANG_HAS_IMAGE != 0 && !l.img.is_empty()).then_some(l.img));
            }
        }
        Ok(None)
    }
}

// ---------------------------------------------------------------------------
// Reader
// ---------------------------------------------------------------------------

/// Read-only mapping of one segment epoch. The mapping stays valid even after
/// the daemon unlinks the file (I3 in the module docs).
pub struct SegmentReader {
    ptr: *mut u8,
    len: usize,
    pub epoch: u64,
    pub slot_count: u64,
    slots_off: u64,
    arena_off: u64,
}

unsafe impl Send for SegmentReader {}

impl Drop for SegmentReader {
    fn drop(&mut self) {
        // Long-lived Apache workers remap on every epoch flip; the old mapping
        // must be released or the worker leaks one segment per compaction.
        unsafe { libc::munmap(self.ptr as *mut libc::c_void, self.len) };
    }
}

impl SegmentReader {
    /// Open + validate a segment. Any mismatch is an error (caller falls back).
    pub fn open(dir: &Path, epoch: u64, root_hash: u64) -> io::Result<SegmentReader> {
        let path = segment_file(dir, epoch);
        let cpath = CString::new(path.as_os_str().as_bytes())
            .map_err(|_| io::Error::new(io::ErrorKind::InvalidInput, "path contains NUL"))?;
        let fd = unsafe { libc::open(cpath.as_ptr(), libc::O_RDONLY) };
        if fd < 0 {
            return Err(io::Error::last_os_error());
        }
        let mut st: libc::stat = unsafe { std::mem::zeroed() };
        if unsafe { libc::fstat(fd, &mut st) } != 0 {
            let e = io::Error::last_os_error();
            unsafe { libc::close(fd) };
            return Err(e);
        }
        let len = st.st_size as usize;
        if len < HEADER_SIZE as usize {
            unsafe { libc::close(fd) };
            return Err(io::Error::new(io::ErrorKind::InvalidData, "segment too small"));
        }
        let ptr = match mmap_fd(fd, len, false) {
            Ok(p) => p,
            Err(e) => {
                unsafe { libc::close(fd) };
                return Err(e);
            }
        };
        unsafe { libc::close(fd) };

        let r = {
            let h = unsafe { &*(ptr as *const SegHeader) };
            let bad = |msg: &str| io::Error::new(io::ErrorKind::InvalidData, msg.to_string());
            if h.magic.load(Ordering::Acquire) != MAGIC {
                Err(bad("bad magic"))
            } else if h.layout_version != LAYOUT_VERSION {
                Err(bad("layout version mismatch"))
            } else if h.epoch != epoch {
                Err(bad("epoch mismatch"))
            } else if root_hash != 0 && h.root_hash != root_hash {
                Err(bad("root mismatch"))
            } else if h.ready.load(Ordering::Acquire) != 1 {
                Err(bad("segment not ready"))
            } else if h.slot_count == 0
                || !h.slot_count.is_power_of_two()
                || h.seg_size != len as u64
                || h.slots_off < HEADER_SIZE
                || h.slot_count
                    .checked_mul(8)
                    .and_then(|s| h.slots_off.checked_add(s))
                    .map(|end| h.arena_off < end)
                    .unwrap_or(true)
                || h.arena_off > len as u64
            {
                Err(bad("inconsistent geometry"))
            } else {
                Ok(SegmentReader {
                    ptr,
                    len,
                    epoch,
                    slot_count: h.slot_count,
                    slots_off: h.slots_off,
                    arena_off: h.arena_off,
                })
            }
        };
        if r.is_err() {
            unsafe { libc::munmap(ptr as *mut libc::c_void, len) };
        }
        r
    }

    pub fn header(&self) -> &SegHeader {
        unsafe { &*(self.ptr as *const SegHeader) }
    }

    /// The whole read-only mapping.
    ///
    /// Needed because a v3 image's strings live in the segment-wide table, not
    /// inside the image: `image::Image` resolves string offsets against this
    /// slice. Borrowed from `&self`, exactly like a `RecordView`, so the two
    /// cannot outlive the mapping independently.
    #[inline]
    pub fn bytes(&self) -> &[u8] {
        unsafe { std::slice::from_raw_parts(self.ptr, self.len) }
    }

    /// Base address of the mapping. The zero-copy read path adds a string's
    /// segment offset to this and hands the result to the Zend engine, so it
    /// must be the same base `bytes()` is measured from.
    #[inline]
    pub fn base_ptr(&self) -> *mut u8 {
        self.ptr
    }

    #[inline]
    fn slot(&self, i: u64) -> &AtomicU64 {
        debug_assert!(i < self.slot_count);
        unsafe { &*(self.ptr.add((self.slots_off + i * 8) as usize) as *const AtomicU64) }
    }

    #[inline]
    fn record_at(&self, off: u64) -> Option<RecordView<'_>> {
        if off < self.arena_off || off as usize + REC_FIXED > self.len {
            return None;
        }
        let bytes = unsafe { std::slice::from_raw_parts(self.ptr.add(off as usize), self.len - off as usize) };
        let rec_len = rd_u32(bytes, 0) as usize;
        if rec_len < REC_FIXED || rec_len > bytes.len() {
            return None;
        }
        RecordView::parse(&bytes[..rec_len])
    }

    /// Probe result: `Found` includes tombstones (definitive absence);
    /// `Absent` = no slot for the name; `Invalid` = a validation failure
    /// (caller should treat the whole lookup as unusable and fall back).
    pub fn lookup(&self, name: &str) -> Lookup<'_> {
        let hash = fnv1a(name.as_bytes());
        let mask = self.slot_count - 1;
        let mut i = hash & mask;
        for _ in 0..self.slot_count {
            let packed = self.slot(i).load(Ordering::Acquire);
            if packed == 0 {
                return Lookup::Absent;
            }
            if slot_tag(packed) == slot_tag(slot_pack(hash, 0)) {
                match self.record_at(slot_off(packed)) {
                    Some(rec) if rec.name_hash == hash && rec.name() == name => {
                        return Lookup::Found(rec);
                    }
                    Some(_) => {} // tag collision with another name — keep probing
                    None => return Lookup::Invalid,
                }
            }
            i = (i + 1) & mask;
        }
        Lookup::Absent
    }

    /// Iterate every live (non-tombstone) record. Slot order is arbitrary;
    /// callers sort what they need sorted.
    ///
    /// The record's lifetime is the READER's, not the callback's: a caller
    /// building an index over the whole segment can keep the `&str`s it is
    /// handed instead of copying every name and path onto the heap. With the
    /// elided (higher-ranked) lifetime this used to have, a 200k-node rollup
    /// had to allocate two Strings per node to outlive the closure.
    pub fn for_each_live<'a>(&'a self, mut f: impl FnMut(&RecordView<'a>)) {
        for i in 0..self.slot_count {
            let packed = self.slot(i).load(Ordering::Acquire);
            if packed == 0 {
                continue;
            }
            if let Some(rec) = self.record_at(slot_off(packed)) {
                if !rec.is_tombstone() {
                    f(&rec);
                }
            }
        }
    }
}

pub enum Lookup<'a> {
    Found(RecordView<'a>),
    Absent,
    Invalid,
}

// ---------------------------------------------------------------------------
// Writer (daemon only; single-threaded)
// ---------------------------------------------------------------------------

/// The daemon's writable mapping of the active segment.
pub struct SegmentWriter {
    ptr: *mut u8,
    len: usize,
    pub epoch: u64,
    pub path: PathBuf,
    slot_count: u64,
    slots_off: u64,
    arena_off: u64,
    /// Occupied slots (live + tombstone); load-factor input for compaction.
    used_slots: u64,
    /// `image::Interner` ID -> offset of that string's `zend_string` in THIS
    /// segment; 0 = not placed yet (offset 0 is the header, never a string).
    ///
    /// Indexed by ID rather than hashed: IDs are dense and assigned in order,
    /// so this is one 4-byte slot per distinct string in the tree — ~350 KB on
    /// hili's production data, against a `HashMap` probe on every string of
    /// every record written.
    str_off: Vec<u32>,
}

unsafe impl Send for SegmentWriter {}

impl Drop for SegmentWriter {
    fn drop(&mut self) {
        unsafe { libc::munmap(self.ptr as *mut libc::c_void, self.len) };
    }
}

/// Why an append could not proceed: the caller must compact into a new epoch.
#[derive(Debug, PartialEq)]
pub enum WriteErr {
    ArenaFull,
    SlotsFull,
}

impl SegmentWriter {
    /// Create `data.<epoch>.shm` sized `seg_size` with `slot_count` slots
    /// (power of two). The file is ftruncate'd (sparse on tmpfs) and starts
    /// NOT ready; call [`SegmentWriter::publish_ready`] after the initial fill.
    pub fn create(
        dir: &Path,
        epoch: u64,
        seg_size: u64,
        backed_bytes: u64,
        slot_count: u64,
        root_hash: u64,
    ) -> io::Result<SegmentWriter> {
        assert!(slot_count.is_power_of_two());
        std::fs::create_dir_all(dir)?;
        let path = segment_file(dir, epoch);
        let cpath = CString::new(path.as_os_str().as_bytes())
            .map_err(|_| io::Error::new(io::ErrorKind::InvalidInput, "path contains NUL"))?;
        let slots_off = HEADER_SIZE;
        let arena_off = {
            let end = slots_off + slot_count * 8;
            end.div_ceil(4096) * 4096
        };
        let seg_size = seg_size.max(arena_off + 4096);

        // Refuse to build a segment the filesystem cannot back.
        //
        // `ftruncate` on tmpfs is sparse: it reserves nothing, so an oversized
        // segment is accepted here and only fails later, on the first write
        // that touches an unbacked page -- as SIGBUS, which cannot be caught
        // and takes the whole daemon with it. Checking up front converts that
        // into an ordinary io::Error the caller already propagates, so the
        // daemon logs, stays alive, and keeps serving the segment it has.
        //
        // What is checked is `backed_bytes`, NOT `seg_size`, and the difference
        // is the whole correctness of this guard. `seg_size` is the sparse
        // extent: the payload twice over, floored by `shm_size_mb` (64 MB by
        // default). Almost none of it is touched -- that is what makes the
        // headroom free. Demanding it up front made a ONE-NODE tree unbuildable
        // on Docker's default 64 MB /dev/shm, so qdbd refused to publish
        // anything and every pod ran permanently on the fallback path: the
        // exact outcome the guard exists to prevent, arrived at by a different
        // route. Ask for the pages the initial fill will really write.
        //
        // Free space is measured with the current segment still on disk, which
        // is correct: a compaction holds the old and the new one at once.
        check_space_for(dir, backed_bytes.max(arena_off + 4096).min(seg_size))?;

        // O_EXCL: an epoch is written exactly once; a leftover file from a
        // crashed daemon is removed first (it was never published as current).
        let _ = std::fs::remove_file(&path);
        let fd = unsafe {
            libc::open(cpath.as_ptr(), libc::O_RDWR | libc::O_CREAT | libc::O_EXCL, 0o644)
        };
        if fd < 0 {
            return Err(io::Error::last_os_error());
        }
        if unsafe { libc::ftruncate(fd, seg_size as libc::off_t) } != 0 {
            let e = io::Error::last_os_error();
            unsafe { libc::close(fd) };
            return Err(e);
        }
        let ptr = match mmap_fd(fd, seg_size as usize, true) {
            Ok(p) => p,
            Err(e) => {
                unsafe { libc::close(fd) };
                return Err(e);
            }
        };
        unsafe { libc::close(fd) };

        let w = SegmentWriter {
            ptr,
            len: seg_size as usize,
            epoch,
            path,
            slot_count,
            slots_off,
            arena_off,
            used_slots: 0,
            str_off: Vec::new(),
        };
        // Plain (non-atomic) header fields are written only before `magic` is
        // published and are immutable afterwards, so raw writes are sound.
        unsafe {
            let h = w.ptr as *mut SegHeader;
            ptr::addr_of_mut!((*h).layout_version).write(LAYOUT_VERSION);
            ptr::addr_of_mut!((*h).epoch).write(epoch);
            ptr::addr_of_mut!((*h).seg_size).write(seg_size);
            ptr::addr_of_mut!((*h).slot_count).write(slot_count);
            ptr::addr_of_mut!((*h).slots_off).write(slots_off);
            ptr::addr_of_mut!((*h).arena_off).write(arena_off);
            (*h).arena_next.store(arena_off, REL);
            ptr::addr_of_mut!((*h).root_hash).write(root_hash);
            ptr::addr_of_mut!((*h).daemon_pid).write(std::process::id() as u64);
            (*h).magic.store(MAGIC, Ordering::Release);
        }
        Ok(w)
    }

    pub fn header(&self) -> &SegHeader {
        unsafe { &*(self.ptr as *const SegHeader) }
    }

    /// Mark the segment fully populated; readers refuse it until this.
    pub fn publish_ready(&self) {
        self.header().ready.store(1, Ordering::Release);
    }

    #[inline]
    fn slot(&self, i: u64) -> &AtomicU64 {
        unsafe { &*(self.ptr.add((self.slots_off + i * 8) as usize) as *const AtomicU64) }
    }

    /// Copy `bytes` into fresh arena space; returns the record offset.
    fn append(&mut self, bytes: &[u8]) -> Result<u64, WriteErr> {
        let h = self.header();
        let off = h.arena_next.load(REL);
        if off + bytes.len() as u64 > self.len as u64 {
            return Err(WriteErr::ArenaFull);
        }
        unsafe {
            ptr::copy_nonoverlapping(bytes.as_ptr(), self.ptr.add(off as usize), bytes.len());
        }
        h.arena_next.store(off + bytes.len() as u64, REL);
        Ok(off)
    }

    /// Offset of `bytes` as a `zend_string` in this segment's arena, placing it
    /// on first use. `id` is the daemon-global `image::Interner` ID, which is
    /// what makes the table segment-wide: every document that uses the string
    /// resolves to this one copy.
    ///
    /// This is the write half of the v3 layout change. The read half is
    /// `image::Image`, which resolves string offsets against the whole segment
    /// mapping rather than against the image it is reading.
    pub fn place_string(&mut self, id: u32, bytes: &[u8]) -> Result<u32, WriteErr> {
        let idx = id as usize;
        if let Some(&off) = self.str_off.get(idx) {
            if off != 0 {
                return Ok(off);
            }
        }
        // Build the entry standalone so `push_zend_string`'s own padding lands
        // at a known place, then append it whole. Its length is a multiple of
        // 8, which is what keeps `arena_next` 8-aligned for the next record —
        // and 8-alignment is what lets PHP point a zval straight at these bytes.
        let mut entry = Vec::with_capacity(crate::php_abi::ZS_HEADER_SIZE + bytes.len() + 8);
        crate::php_abi::push_zend_string(&mut entry, bytes);
        debug_assert_eq!(entry.len() % 8, 0, "string entry must keep the arena 8-aligned");
        let off = self.append(&entry)?;
        // Segment offsets are stored in the image as u32. A segment big enough
        // to break that is far past every other limit here, but a silently
        // truncated offset would be a wild pointer, so refuse it explicitly.
        let off = u32::try_from(off).map_err(|_| WriteErr::ArenaFull)?;
        if self.str_off.len() <= idx {
            self.str_off.resize(idx + 1, 0);
        }
        self.str_off[idx] = off;
        let h = self.header();
        h.str_bytes.fetch_add(entry.len() as u64, REL);
        h.str_count.fetch_add(1, REL);
        Ok(off)
    }

    /// Publish a record (fresh or replacement) under its name. The record bytes
    /// must have been produced by [`encode_record`] for that name.
    pub fn upsert(&mut self, name: &str, record: &[u8]) -> Result<(), WriteErr> {
        // Refuse inserts past 50% occupancy so probe chains stay short.
        if self.used_slots * 2 >= self.slot_count {
            // A replacement of an existing name is still fine; check first.
            if self.find_slot(name).is_none() {
                return Err(WriteErr::SlotsFull);
            }
        }
        let off = self.append(record)?;
        let hash = fnv1a(name.as_bytes());
        match self.find_slot(name) {
            Some(i) => {
                let old = self.slot(i).load(REL);
                let old_len = self
                    .record_len_at(slot_off(old))
                    .unwrap_or(0);
                self.slot(i).store(slot_pack(hash, off), Ordering::Release);
                self.header().dead_bytes.fetch_add(old_len, REL);
            }
            None => {
                let mask = self.slot_count - 1;
                let mut i = hash & mask;
                loop {
                    if self.slot(i).load(REL) == 0 {
                        self.slot(i).store(slot_pack(hash, off), Ordering::Release);
                        self.used_slots += 1;
                        break;
                    }
                    i = (i + 1) & mask;
                }
            }
        }
        Ok(())
    }

    fn record_len_at(&self, off: u64) -> Option<u64> {
        if off < self.arena_off || off as usize + 4 > self.len {
            return None;
        }
        let bytes =
            unsafe { std::slice::from_raw_parts(self.ptr.add(off as usize), 4) };
        Some(rd_u32(bytes, 0) as u64)
    }

    /// Slot index currently holding `name` (live or tombstone), if any.
    fn find_slot(&self, name: &str) -> Option<u64> {
        let hash = fnv1a(name.as_bytes());
        let mask = self.slot_count - 1;
        let mut i = hash & mask;
        for _ in 0..self.slot_count {
            let packed = self.slot(i).load(REL);
            if packed == 0 {
                return None;
            }
            if slot_tag(packed) == slot_tag(slot_pack(hash, 0)) {
                let off = slot_off(packed) as usize;
                if off + REC_FIXED <= self.len {
                    let bytes = unsafe {
                        std::slice::from_raw_parts(self.ptr.add(off), self.len - off)
                    };
                    let rec_len = rd_u32(bytes, 0) as usize;
                    if rec_len >= REC_FIXED && rec_len <= bytes.len() {
                        if let Some(rec) = RecordView::parse(&bytes[..rec_len]) {
                            if rec.name_hash == hash && rec.name() == name {
                                return Some(i);
                            }
                        }
                    }
                }
            }
            i = (i + 1) & mask;
        }
        None
    }

    /// Swap `name`'s slot to a tombstone record (definitive absence).
    /// A name with no slot needs nothing: probing already answers Absent.
    pub fn tombstone(&mut self, name: &str, generation: u64) -> Result<(), WriteErr> {
        let Some(i) = self.find_slot(name) else {
            return Ok(());
        };
        let rec = encode_tombstone(name, generation);
        let off = self.append(&rec)?;
        let old = self.slot(i).load(REL);
        let old_len = self.record_len_at(slot_off(old)).unwrap_or(0);
        self.slot(i)
            .store(slot_pack(fnv1a(name.as_bytes()), off), Ordering::Release);
        let h = self.header();
        h.dead_bytes.fetch_add(old_len + rec.len() as u64, REL);
        h.tombstone_count.fetch_add(1, REL);
        Ok(())
    }

    /// True when the next write should go to a fresh, compacted segment:
    /// slots past half occupancy or dead bytes past max(arena/4, 4 MB).
    pub fn should_compact(&self) -> bool {
        let h = self.header();
        let used = h.arena_next.load(REL) - self.arena_off;
        let dead = h.dead_bytes.load(REL);
        self.used_slots * 2 >= self.slot_count || dead > (used / 4).max(4 << 20)
    }

    /// `doc_bytes` is the documents' size ON DISK; `raw_bytes` is how much of
    /// that the segment actually holds (0 once the daemon stops shipping raw
    /// JSON). Both come from the daemon because only it knows the difference.
    pub fn set_counts(
        &self,
        nodes: u64,
        links: u64,
        doc_bytes: u64,
        img_bytes: u64,
        raw_bytes: u64,
    ) {
        let h = self.header();
        h.node_count.store(nodes, REL);
        h.link_count.store(links, REL);
        h.doc_bytes.store(doc_bytes, REL);
        h.img_bytes.store(img_bytes, REL);
        h.raw_bytes.store(raw_bytes, REL);
    }

    /// Bytes and entries the segment-wide string table has placed so far.
    pub fn string_stats(&self) -> (u64, u64) {
        let h = self.header();
        (h.str_bytes.load(REL), h.str_count.load(REL))
    }
}

/// Slot count for an expected number of nodes: 4x headroom, min 8192, pow2.
pub fn slot_count_for(nodes: u64) -> u64 {
    (nodes.saturating_mul(4)).max(8192).next_power_of_two()
}

/// Headroom demanded on top of a segment before we agree to build it, so a
/// segment that only *just* fits does not leave the filesystem with no room for
/// the metrics arena or a leftover epoch file.
const SEG_SPACE_MARGIN: u64 = 8 << 20;

/// Fail unless `dir`'s filesystem can currently back `need` bytes.
///
/// This is the guard that turns shm exhaustion from an uncatchable SIGBUS on
/// first page touch into a plain `StorageFull` error. It is advisory -- another
/// writer can consume the space between the check and the write -- but the
/// daemon is the single writer of segments, so in practice the only racing
/// consumer is the rest of the pod.
pub fn check_space_for(dir: &Path, need: u64) -> io::Result<()> {
    let cdir = CString::new(dir.as_os_str().as_bytes())
        .map_err(|_| io::Error::new(io::ErrorKind::InvalidInput, "path contains NUL"))?;
    let mut st: libc::statvfs = unsafe { std::mem::zeroed() };
    if unsafe { libc::statvfs(cdir.as_ptr(), &mut st) } != 0 {
        // Cannot tell: do not invent a failure, let the write decide as before.
        return Ok(());
    }
    // `f_bavail` counts units of `f_frsize` (POSIX), with `f_bsize` as the
    // fallback for the filesystems that leave `f_frsize` unset.
    //
    // The conversions widen `c_ulong`, which is 32-bit on a 32-bit target. On
    // the 64-bit targets this actually ships to they are no-ops, which is what
    // the allow is for — dropping them to satisfy the lint would leave the
    // multiplication below silently overflow-prone anywhere else.
    #[allow(clippy::useless_conversion)]
    let (unit, avail): (u64, u64) = (
        if st.f_frsize > 0 { st.f_frsize.into() } else { st.f_bsize.into() },
        st.f_bavail.into(),
    );
    let avail = avail.saturating_mul(unit);
    let want = need.saturating_add(SEG_SPACE_MARGIN);
    if avail < want {
        return Err(io::Error::new(
            io::ErrorKind::StorageFull,
            format!(
                "segment payload needs {} bytes (+{} margin) but {} has only {} free -- \
                 refusing to build it rather than taking SIGBUS on first touch; \
                 raise the /dev/shm size limit (>= 2x the tree's live bytes, since a \
                 compaction holds the old segment and the new one at once)",
                need,
                SEG_SPACE_MARGIN,
                dir.display(),
                avail
            ),
        ));
    }
    Ok(())
}

/// Bytes a fresh segment will actually TOUCH: header, slot directory and the
/// payload itself, plus a little slack.
///
/// The counterpart to [`seg_size_for`], and the two must not be confused.
/// `seg_size_for` returns the file's sparse extent -- growth headroom that
/// costs nothing until written. This returns the storage the filesystem has to
/// find today, which is what [`check_space_for`] must be asked about: sizing
/// the check off the extent instead refuses a tiny tree on a default-sized
/// /dev/shm.
pub fn seg_backed_for(live_bytes: u64, slot_count: u64) -> u64 {
    (HEADER_SIZE + slot_count * 8).div_ceil(4096) * 4096 + live_bytes + (1 << 20)
}

/// Segment byte budget: enough for the live payload twice over plus the slot
/// directory, floored by the configured budget (sparse until touched).
pub fn seg_size_for(live_bytes: u64, slot_count: u64, budget_bytes: u64) -> u64 {
    let need = HEADER_SIZE + slot_count * 8 + live_bytes.saturating_mul(2) + (1 << 20);
    need.max(budget_bytes).div_ceil(4096) * 4096
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn space_check_passes_when_the_filesystem_has_room() {
        // /tmp is not going to be 8MB from full in CI; a small ask must pass.
        assert!(check_space_for(Path::new("/tmp"), 1024).is_ok());
    }

    #[test]
    fn space_check_refuses_an_impossible_segment() {
        // The guard that replaces SIGBUS-on-first-touch: an ask no filesystem
        // can satisfy must come back as StorageFull, not as a successful
        // sparse allocation that faults later.
        let e = check_space_for(Path::new("/tmp"), u64::MAX / 2)
            .expect_err("an impossible segment must be refused");
        assert_eq!(e.kind(), io::ErrorKind::StorageFull);
        // The message has to say what to do -- it is the only breadcrumb the
        // operator gets when the daemon stops publishing.
        assert!(e.to_string().contains("/dev/shm"), "message must name the fix: {e}");
    }

    #[test]
    fn the_space_check_sizes_the_payload_not_the_sparse_extent() {
        // The regression this exists for: with the default 64 MB budget, a
        // one-node tree's segment EXTENT is the whole 64 MB, and demanding that
        // free (plus the margin) is unsatisfiable on Docker's default 64 MB
        // /dev/shm -- so qdbd refused to publish at all and every pod ran
        // permanently degraded. The suite's own runner passes --shm-size=256m,
        // so only an assertion on the numbers catches this.
        let slots = slot_count_for(1);
        let budget: u64 = 64 << 20;
        assert_eq!(
            seg_size_for(4096, slots, budget),
            budget,
            "a tiny tree still gets the full budget as sparse headroom"
        );
        let backed = seg_backed_for(4096, slots);
        assert!(
            backed + SEG_SPACE_MARGIN < budget,
            "a tiny tree must fit a default-sized /dev/shm: backed {backed} + margin \
             {SEG_SPACE_MARGIN} must be under {budget}"
        );
    }

    #[test]
    fn the_backed_size_covers_the_slot_directory_and_the_payload() {
        // It has to be an over-estimate of what gets written, never an under-
        // estimate: the point is that no page the fill touches is unbacked.
        let slots = slot_count_for(10_000);
        let live = 32 << 20;
        let backed = seg_backed_for(live, slots);
        assert!(backed > live + slots * 8, "payload and slots are both counted");
        assert!(
            backed < seg_size_for(live, slots, 0),
            "but it stays under the extent, which budgets the payload twice"
        );
    }

    #[test]
    fn space_check_is_silent_when_it_cannot_measure() {
        // An unstattable path must not invent a failure: fall through to the
        // old behaviour rather than refusing to build a segment that may fit.
        assert!(check_space_for(Path::new("/definitely/not/a/real/path"), 1024).is_ok());
    }

    fn tmpdir(tag: &str) -> PathBuf {
        let d = std::env::temp_dir().join(format!("qdb_shm_{tag}_{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&d);
        std::fs::create_dir_all(&d).unwrap();
        d
    }

    fn mk_input<'a>(
        name: &'a str,
        rel: &'a str,
        father: Option<&'a str>,
        generation: u64,
        children: &'a [(String, bool)],
        inlinks: &'a [String],
        langs: &'a [LangDoc<'a>],
    ) -> RecordInput<'a> {
        RecordInput {
            name,
            rel_path: rel,
            father,
            generation,
            mtime: 1234,
            children,
            inlinks,
            langs,
        }
    }

    #[test]
    fn record_round_trip() {
        let children = vec![("alpha".to_string(), false), ("beta".to_string(), true)];
        let inlinks = vec!["cont1".to_string(), "cont2".to_string()];
        let doc = br#"{"title":"Home"}"#;
        let langs = vec![
            LangDoc { lang: "", doc, corrupt: false, doc_mtime: 111, doc_size: doc.len() as i64, image: &[] },
            LangDoc { lang: "it", doc: b"", corrupt: true, doc_mtime: 222, doc_size: 3, image: &[] },
        ];
        let input = mk_input("home", "home", None, 7, &children, &inlinks, &langs);
        let bytes = encode_record(&input, false);
        assert_eq!(bytes.len() % 8, 0);

        let rec = RecordView::parse(&bytes).expect("parses");
        assert!(!rec.is_tombstone());
        assert_eq!(rec.name(), "home");
        assert_eq!(rec.rel_path(), "home");
        assert_eq!(rec.father(), None);
        assert_eq!(rec.generation, 7);
        assert_eq!(rec.mtime, 1234);
        assert_eq!(rec.children(), vec![("alpha", false), ("beta", true)]);
        assert_eq!(rec.inlinks(), vec!["cont1", "cont2"]);
        assert_eq!(rec.doc("").unwrap().stored().unwrap(), doc);
        assert!(rec.doc("it").is_err(), "corrupt lang surfaces as Err");
        assert_eq!(rec.doc("de").unwrap(), DocBytes::Absent);

        let ls = rec.langs();
        assert_eq!(ls.len(), 2);
        assert_eq!(ls[0].lang, "");
        assert_eq!(ls[1].lang, "it");
        assert_eq!(ls[1].doc_mtime, 222);
    }

    #[test]
    fn record_carries_per_language_images() {
        // Images must survive the record round trip, stay 8-aligned relative to
        // the record start (records themselves are 8-aligned in the arena, so
        // that is what makes every zend_string inside them aligned in the
        // mapping), and be reported per language.
        let img_a: Vec<u8> = (0..40u8).collect();
        let img_b: Vec<u8> = (0..24u8).map(|b| b + 100).collect();
        let langs = vec![
            LangDoc {
                lang: "",
                doc: b"{\"a\":1}",
                corrupt: false,
                doc_mtime: 1,
                doc_size: 7,
                image: &img_a,
            },
            LangDoc {
                lang: "en",
                doc: b"{\"b\":22}",
                corrupt: false,
                doc_mtime: 2,
                doc_size: 8,
                image: &img_b,
            },
            // A language with a document but deliberately no image.
            LangDoc {
                lang: "de",
                doc: b"{}",
                corrupt: false,
                doc_mtime: 3,
                doc_size: 2,
                image: &[],
            },
        ];
        let input = mk_input("n", "home/n", Some("home"), 5, &[], &[], &langs);
        let bytes = encode_record(&input, false);
        assert_eq!(bytes.len() % 8, 0);

        let rec = RecordView::parse(&bytes).expect("parses");
        assert_eq!(rec.image("").unwrap(), Some(&img_a[..]));
        assert_eq!(rec.image("en").unwrap(), Some(&img_b[..]));
        assert_eq!(rec.image("de").unwrap(), None, "no image for 'de'");
        assert_eq!(rec.image("fr").unwrap(), None, "absent language");
        // Documents still readable alongside their images.
        assert_eq!(rec.doc("").unwrap(), DocBytes::Stored(&b"{\"a\":1}"[..]));
        assert_eq!(rec.doc("en").unwrap(), DocBytes::Stored(&b"{\"b\":22}"[..]));

        for l in rec.langs() {
            if l.img.is_empty() {
                continue;
            }
            let off = l.img.as_ptr() as usize - bytes.as_ptr() as usize;
            assert_eq!(off % 8, 0, "image must be 8-aligned within the record");
        }

        // Truncation anywhere must be rejected, never read out of bounds.
        for cut in 0..bytes.len() {
            let _ = RecordView::parse(&bytes[..cut]);
        }
    }

    /// A record built the way the daemon builds them from layout v3 on: image
    /// present, document bytes absent. The distinction a reader depends on is
    /// "exists but not stored" vs "no such language", and an empty byte slice
    /// cannot express it — which is what LANG_HAS_RAW is for.
    #[test]
    fn a_document_without_stored_bytes_is_not_an_absent_one() {
        let img = vec![0u8; 32];
        let langs = vec![
            LangDoc {
                lang: "",
                doc: &[],
                corrupt: false,
                doc_mtime: 1,
                doc_size: 99,
                image: &img,
            },
        ];
        let children: Vec<(String, bool)> = Vec::new();
        let inlinks: Vec<String> = Vec::new();
        let bytes = encode_record(
            &RecordInput {
                name: "n",
                rel_path: "n",
                father: None,
                generation: 1,
                mtime: 0,
                children: &children,
                inlinks: &inlinks,
                langs: &langs,
            },
            false,
        );
        let rec = RecordView::parse(&bytes).expect("record parses");
        assert_eq!(rec.doc("").unwrap(), DocBytes::NotStored);
        assert!(rec.doc("").unwrap().exists(), "the document still exists");
        assert_eq!(rec.doc("xx").unwrap(), DocBytes::Absent);
        assert!(!rec.doc("xx").unwrap().exists());
        // The size the daemon recorded survives even though the bytes did not.
        assert_eq!(rec.langs()[0].doc_size, 99);
        // ...and the image is still reachable, which is the whole point.
        assert_eq!(rec.image("").unwrap().map(<[u8]>::len), Some(32));
    }

    #[test]
    fn corrupt_language_reports_no_image() {
        let img: Vec<u8> = (0..16u8).collect();
        let langs = vec![LangDoc {
            lang: "",
            doc: b"",
            corrupt: true,
            doc_mtime: 1,
            doc_size: 5,
            image: &img,
        }];
        let input = mk_input("c", "home/c", Some("home"), 1, &[], &[], &langs);
        let bytes = encode_record(&input, false);
        let rec = RecordView::parse(&bytes).expect("parses");
        assert!(rec.image("").is_err(), "corrupt language must report corrupt");
        assert!(rec.doc("").is_err());
    }

    #[test]
    fn truncated_record_never_panics() {
        let children: Vec<(String, bool)> = vec![("x".into(), false)];
        let langs =
            vec![LangDoc { lang: "", doc: b"{}", corrupt: false, doc_mtime: 0, doc_size: 2, image: &[] }];
        let input = mk_input("n", "n", Some("f"), 1, &children, &[], &langs);
        let bytes = encode_record(&input, false);
        for cut in 0..bytes.len() {
            let _ = RecordView::parse(&bytes[..cut]); // must not panic
        }
    }

    #[test]
    fn writer_reader_lookup_tombstone() {
        let dir = tmpdir("rw");
        let mut w = SegmentWriter::create(&dir, 1, 1 << 20, seg_backed_for(0, 8192), 8192, 42).unwrap();
        for i in 0..500 {
            let name = format!("node-{i}");
            let rel = format!("home/node-{i}");
            let doc = format!("{{\"n\":{i}}}");
            let langs = vec![LangDoc {
                lang: "",
                doc: doc.as_bytes(),
                corrupt: false,
                doc_mtime: i,
                doc_size: doc.len() as i64, image: &[] }];
            let input = mk_input(&name, &rel, Some("home"), i as u64 + 1, &[], &[], &langs);
            w.upsert(&name, &encode_record(&input, false)).unwrap();
        }
        w.set_counts(500, 0, 0, 0, 0);
        w.publish_ready();

        let r = SegmentReader::open(&dir, 1, 42).unwrap();
        assert_eq!(r.header().node_count.load(REL), 500);
        match r.lookup("node-123") {
            Lookup::Found(rec) => {
                assert_eq!(rec.rel_path(), "home/node-123");
                assert_eq!(rec.doc("").unwrap().stored().unwrap(), br#"{"n":123}"#);
            }
            _ => panic!("node-123 should be found"),
        }
        assert!(matches!(r.lookup("nope"), Lookup::Absent));

        // Replace one record, tombstone another; reader observes both.
        let doc = br#"{"n":"updated"}"#;
        let langs = vec![LangDoc {
            lang: "",
            doc,
            corrupt: false,
            doc_mtime: 9,
            doc_size: doc.len() as i64, image: &[] }];
        let input = mk_input("node-7", "home/node-7", Some("home"), 999, &[], &[], &langs);
        w.upsert("node-7", &encode_record(&input, false)).unwrap();
        w.tombstone("node-9", 1000).unwrap();

        match r.lookup("node-7") {
            Lookup::Found(rec) => {
                assert_eq!(rec.generation, 999);
                assert_eq!(rec.doc("").unwrap().stored().unwrap(), doc);
            }
            _ => panic!("node-7 should be found"),
        }
        match r.lookup("node-9") {
            Lookup::Found(rec) => assert!(rec.is_tombstone(), "tombstone visible"),
            _ => panic!("node-9 slot should still resolve"),
        }

        // Every live record is iterable (500 - 1 tombstoned).
        let mut n = 0;
        r.for_each_live(|_| n += 1);
        assert_eq!(n, 499);

        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn arena_full_signals_compaction() {
        let dir = tmpdir("full");
        // Tiny arena: header + slots + ~8 KB of records.
        let mut w = SegmentWriter::create(&dir, 1, 0, seg_backed_for(0, 8192), 8192, 0).unwrap();
        let doc = vec![b'x'; 512];
        let json = format!("{{\"pad\":\"{}\"}}", String::from_utf8_lossy(&doc));
        let mut hit_full = false;
        for i in 0..1000 {
            let name = format!("n{i}");
            let langs = vec![LangDoc {
                lang: "",
                doc: json.as_bytes(),
                corrupt: false,
                doc_mtime: 0,
                doc_size: json.len() as i64, image: &[] }];
            let input = mk_input(&name, &name, None, 1, &[], &[], &langs);
            match w.upsert(&name, &encode_record(&input, false)) {
                Ok(()) => {}
                Err(WriteErr::ArenaFull) => {
                    hit_full = true;
                    break;
                }
                Err(e) => panic!("unexpected {e:?}"),
            }
        }
        assert!(hit_full, "small arena must fill up");
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn reader_during_churn_never_sees_torn_records() {
        use std::sync::atomic::AtomicBool;
        use std::sync::Arc;

        let dir = tmpdir("churn");
        let mut w = SegmentWriter::create(&dir, 1, 8 << 20, seg_backed_for(0, 8192), 8192, 0).unwrap();
        // Seed, publish, then churn while readers hammer lookups.
        for i in 0..64 {
            let name = format!("churn-{i}");
            let json = format!("{{\"v\":0,\"i\":{i}}}");
            let langs = vec![LangDoc {
                lang: "",
                doc: json.as_bytes(),
                corrupt: false,
                doc_mtime: 0,
                doc_size: json.len() as i64, image: &[] }];
            let input = mk_input(&name, &name, None, 1, &[], &[], &langs);
            w.upsert(&name, &encode_record(&input, false)).unwrap();
        }
        w.publish_ready();

        let stop = Arc::new(AtomicBool::new(false));
        let mut handles = Vec::new();
        for t in 0..4 {
            let stop = stop.clone();
            let dir = dir.clone();
            handles.push(std::thread::spawn(move || {
                let r = SegmentReader::open(&dir, 1, 0).unwrap();
                let mut checked = 0u64;
                while !stop.load(Ordering::Relaxed) {
                    let name = format!("churn-{}", (checked + t) % 64);
                    match r.lookup(&name) {
                        Lookup::Found(rec) => {
                            // The doc must always be a complete JSON object.
                            let doc = rec.doc("").expect("never corrupt").stored().expect("present");
                            assert!(doc.starts_with(b"{") && doc.ends_with(b"}"));
                            assert_eq!(rec.name(), name);
                        }
                        Lookup::Absent => panic!("seeded name must resolve"),
                        Lookup::Invalid => panic!("validation failure under churn"),
                    }
                    checked += 1;
                }
                checked
            }));
        }

        for round in 1..200u64 {
            for i in 0..64 {
                let name = format!("churn-{i}");
                let json = format!("{{\"v\":{round},\"i\":{i},\"pad\":\"{}\"}}", "y".repeat((round % 40) as usize));
                let langs = vec![LangDoc {
                    lang: "",
                    doc: json.as_bytes(),
                    corrupt: false,
                    doc_mtime: 0,
                    doc_size: json.len() as i64, image: &[] }];
                let input = mk_input(&name, &name, None, round + 1, &[], &[], &langs);
                if w.upsert(&name, &encode_record(&input, false)).is_err() {
                    break; // arena filled — enough churn happened either way
                }
            }
        }
        stop.store(true, Ordering::Relaxed);
        for h in handles {
            let checked = h.join().unwrap();
            assert!(checked > 0);
        }
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn slot_helpers() {
        assert_eq!(slot_count_for(0), 8192);
        assert_eq!(slot_count_for(12_300), 65536);
        let h = fnv1a(b"abc");
        let packed = slot_pack(h, 12345);
        assert_eq!(slot_off(packed), 12345);
        assert_eq!(slot_tag(packed), h & 0xffff_0000_0000_0000);
    }
}
