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
pub const LAYOUT_VERSION: u32 = 1;
/// Fixed header block; slot directory starts right after it.
pub const HEADER_SIZE: u64 = 4096;

const REL: Ordering = Ordering::Relaxed;

/// Record flag: the name is known-absent (deleted); probing must not stop early
/// (linear-probe chains stay intact), but a lookup answers "definitively gone".
pub const REC_TOMBSTONE: u16 = 1;
/// Per-language flag: the doc file exists but does not parse. Readers must
/// surface CORRUPT_JSON (contract §7), not "no document".
pub const LANG_CORRUPT: u16 = 1;

/// Fixed-size record header preceding the variable section (see `encode_record`).
pub const REC_FIXED: usize = 44;
/// Fixed part of one language-table entry (lang/doc bytes follow all entries).
pub const LANG_FIXED: usize = 24;

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
///                              i64 doc_mtime, i64 doc_size}
///                then per lang: lang bytes | doc bytes
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
    for l in r.langs {
        put_u16(&mut buf, l.lang.len() as u16);
        put_u16(&mut buf, if l.corrupt { LANG_CORRUPT } else { 0 });
        put_u32(&mut buf, l.doc.len() as u32);
        put_i64(&mut buf, l.doc_mtime);
        put_i64(&mut buf, l.doc_size);
    }
    for l in r.langs {
        buf.extend_from_slice(l.lang.as_bytes());
        buf.extend_from_slice(l.doc);
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

pub struct LangView<'a> {
    pub lang: &'a str,
    pub flags: u16,
    pub doc: &'a [u8],
    pub doc_mtime: i64,
    pub doc_size: i64,
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
        Some(v)
    }

    pub fn is_tombstone(&self) -> bool {
        self.flags & REC_TOMBSTONE != 0
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
            out.push(LangView {
                lang,
                flags,
                doc,
                doc_mtime: rd_i64(self.bytes, e + 8),
                doc_size: rd_i64(self.bytes, e + 16),
            });
        }
        out
    }

    /// The raw JSON document for `lang`. `Ok(None)` = no such language file;
    /// `Err(())` = the file exists but is corrupt (caller maps to CORRUPT_JSON).
    pub fn doc(&self, lang: &str) -> Result<Option<&'a [u8]>, ()> {
        for l in self.langs() {
            if l.lang == lang {
                if l.flags & LANG_CORRUPT != 0 {
                    return Err(());
                }
                return Ok(Some(l.doc));
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
    pub fn for_each_live(&self, mut f: impl FnMut(&RecordView<'_>)) {
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
        slot_count: u64,
        root_hash: u64,
    ) -> io::Result<SegmentWriter> {
        assert!(slot_count.is_power_of_two());
        std::fs::create_dir_all(dir)?;
        let path = segment_file(dir, epoch);
        let cpath = CString::new(path.as_os_str().as_bytes())
            .map_err(|_| io::Error::new(io::ErrorKind::InvalidInput, "path contains NUL"))?;
        // O_EXCL: an epoch is written exactly once; a leftover file from a
        // crashed daemon is removed first (it was never published as current).
        let _ = std::fs::remove_file(&path);
        let fd = unsafe {
            libc::open(cpath.as_ptr(), libc::O_RDWR | libc::O_CREAT | libc::O_EXCL, 0o644)
        };
        if fd < 0 {
            return Err(io::Error::last_os_error());
        }
        let slots_off = HEADER_SIZE;
        let arena_off = {
            let end = slots_off + slot_count * 8;
            end.div_ceil(4096) * 4096
        };
        let seg_size = seg_size.max(arena_off + 4096);
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

    pub fn set_counts(&self, nodes: u64, links: u64, doc_bytes: u64) {
        let h = self.header();
        h.node_count.store(nodes, REL);
        h.link_count.store(links, REL);
        h.doc_bytes.store(doc_bytes, REL);
    }
}

/// Slot count for an expected number of nodes: 4x headroom, min 8192, pow2.
pub fn slot_count_for(nodes: u64) -> u64 {
    (nodes.saturating_mul(4)).max(8192).next_power_of_two()
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
            LangDoc { lang: "", doc, corrupt: false, doc_mtime: 111, doc_size: doc.len() as i64 },
            LangDoc { lang: "it", doc: b"", corrupt: true, doc_mtime: 222, doc_size: 3 },
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
        assert_eq!(rec.doc("").unwrap().unwrap(), doc);
        assert!(rec.doc("it").is_err(), "corrupt lang surfaces as Err");
        assert_eq!(rec.doc("de").unwrap(), None);

        let ls = rec.langs();
        assert_eq!(ls.len(), 2);
        assert_eq!(ls[0].lang, "");
        assert_eq!(ls[1].lang, "it");
        assert_eq!(ls[1].doc_mtime, 222);
    }

    #[test]
    fn truncated_record_never_panics() {
        let children: Vec<(String, bool)> = vec![("x".into(), false)];
        let langs =
            vec![LangDoc { lang: "", doc: b"{}", corrupt: false, doc_mtime: 0, doc_size: 2 }];
        let input = mk_input("n", "n", Some("f"), 1, &children, &[], &langs);
        let bytes = encode_record(&input, false);
        for cut in 0..bytes.len() {
            let _ = RecordView::parse(&bytes[..cut]); // must not panic
        }
    }

    #[test]
    fn writer_reader_lookup_tombstone() {
        let dir = tmpdir("rw");
        let mut w = SegmentWriter::create(&dir, 1, 1 << 20, 8192, 42).unwrap();
        for i in 0..500 {
            let name = format!("node-{i}");
            let rel = format!("home/node-{i}");
            let doc = format!("{{\"n\":{i}}}");
            let langs = vec![LangDoc {
                lang: "",
                doc: doc.as_bytes(),
                corrupt: false,
                doc_mtime: i,
                doc_size: doc.len() as i64,
            }];
            let input = mk_input(&name, &rel, Some("home"), i as u64 + 1, &[], &[], &langs);
            w.upsert(&name, &encode_record(&input, false)).unwrap();
        }
        w.set_counts(500, 0, 0);
        w.publish_ready();

        let r = SegmentReader::open(&dir, 1, 42).unwrap();
        assert_eq!(r.header().node_count.load(REL), 500);
        match r.lookup("node-123") {
            Lookup::Found(rec) => {
                assert_eq!(rec.rel_path(), "home/node-123");
                assert_eq!(rec.doc("").unwrap().unwrap(), br#"{"n":123}"#);
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
            doc_size: doc.len() as i64,
        }];
        let input = mk_input("node-7", "home/node-7", Some("home"), 999, &[], &[], &langs);
        w.upsert("node-7", &encode_record(&input, false)).unwrap();
        w.tombstone("node-9", 1000).unwrap();

        match r.lookup("node-7") {
            Lookup::Found(rec) => {
                assert_eq!(rec.generation, 999);
                assert_eq!(rec.doc("").unwrap().unwrap(), doc);
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
        let mut w = SegmentWriter::create(&dir, 1, 0, 8192, 0).unwrap();
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
                doc_size: json.len() as i64,
            }];
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
        let mut w = SegmentWriter::create(&dir, 1, 8 << 20, 8192, 0).unwrap();
        // Seed, publish, then churn while readers hammer lookups.
        for i in 0..64 {
            let name = format!("churn-{i}");
            let json = format!("{{\"v\":0,\"i\":{i}}}");
            let langs = vec![LangDoc {
                lang: "",
                doc: json.as_bytes(),
                corrupt: false,
                doc_mtime: 0,
                doc_size: json.len() as i64,
            }];
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
                            let doc = rec.doc("").expect("never corrupt").expect("present");
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
                    doc_size: json.len() as i64,
                }];
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
