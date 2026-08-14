//! PHP ABI facts the pre-decoded document image depends on.
//!
//! PHP-free on purpose (std only), exactly like `shm.rs` and `metrics.rs`, so
//! `qdbd` — which does not link PHP — can *write* structures the extension
//! hands straight to the Zend engine. The extension checks every constant here
//! against the real headers it was compiled against before it trusts an image
//! (see `check_php_abi` in lib.rs); a mismatch disables the image path rather
//! than corrupting anything.
//!
//! Everything below was verified against `php:8.5-fpm`
//! (`/usr/local/include/php/Zend/zend_types.h`, `zend_string.h`). The layout and
//! the flag values are byte-for-byte identical from 8.2 through 8.5, which is
//! what lets one set of constants cover the whole range — but that is a fact
//! about those releases, not a guarantee, so `check_php_abi` in lib.rs re-checks
//! it against the headers the extension was actually compiled with.

// ---------------------------------------------------------------------------
// zend_string
// ---------------------------------------------------------------------------
//
//   struct _zend_string {
//       zend_refcounted_h gc;   // u32 refcount + u32 type_info   (offset 0)
//       zend_ulong        h;    // hash value                     (offset 8)
//       size_t            len;  //                                (offset 16)
//       char              val[1];                                 (offset 24)
//   };

/// Bytes before `val`. Also the alignment we pad string entries to.
pub const ZS_HEADER_SIZE: usize = 24;
pub const ZS_OFF_GC: usize = 0;
pub const ZS_OFF_H: usize = 8;
pub const ZS_OFF_LEN: usize = 16;
pub const ZS_OFF_VAL: usize = 24;

/// `IS_STRING` (zend_types.h: the zval type tag for strings).
pub const IS_STRING: u32 = 6;
/// `GC_NOT_COLLECTABLE` = 1<<4 — every zend_string carries it.
pub const GC_NOT_COLLECTABLE: u32 = 1 << 4;
/// `GC_IMMUTABLE` = 1<<6, aliased by `IS_STR_INTERNED`.
pub const GC_IMMUTABLE: u32 = 1 << 6;
/// `GC_FLAGS_SHIFT` — 0 on 8.2 through 8.5, i.e. flags live in the low bits of
/// type_info.
pub const GC_FLAGS_SHIFT: u32 = 0;

/// `GC_STRING` = `IS_STRING | (GC_NOT_COLLECTABLE << GC_FLAGS_SHIFT)` = 22.
pub const GC_STRING: u32 = IS_STRING | (GC_NOT_COLLECTABLE << GC_FLAGS_SHIFT);

/// `type_info` stamped into every string the image carries: a `zend_string`
/// flagged interned, so the engine treats it as immutable and
/// `zend_string_release()` on it is a no-op.
///
/// Deliberately WITHOUT `IS_STR_PERSISTENT`/`IS_STR_PERMANENT`: these strings
/// live in a mapping that is only guaranteed for the current request (the
/// extension pins the segment until `post_deactivate`). Those flags advertise
/// "safe to keep forever", and `zend_string_dup(s, persistent)` hands interned
/// strings back unchanged — an extension that believed the flag would retain a
/// pointer past the mapping's life.
pub const ZS_TYPE_INFO_INTERNED: u32 = GC_STRING | (GC_IMMUTABLE << GC_FLAGS_SHIFT);

/// Refcount stamped into image strings. Inert — interned strings are never
/// refcounted — but PHP's own initialisers write 1, so we match them.
pub const ZS_REFCOUNT: u32 = 1;

/// PHP's `zend_inline_hash_func` (DJBX33A), byte-for-byte.
///
/// Three details that are easy to get wrong and fail *silently*:
///
/// 1. The result must never be zero, so PHP ORs in the top bit
///    (`zend_string.h`: "Hash value can't be zero, so we always set the high
///    bit"). This matters beyond correctness: `zend_string_hash_val()` returns
///    `ZSTR_H(s) ? ZSTR_H(s) : zend_string_hash_func(s)`, and that fallback
///    *writes the hash back into the string* — into our read-only mapping.
///    A zero hash here is a SIGSEGV, not a slow path.
/// 2. Bytes are accumulated with the target's `char` signedness. On x86_64
///    `char` is signed and PHP's unrolled loop uses `str[i]` directly, so bytes
///    >= 0x80 sign-extend; on aarch64 `char` is unsigned and PHP's variant
///    masks with `& 0xff`. Get this wrong and only non-ASCII keys break.
/// 3. `zend_ulong` is 64-bit here; the 32-bit branch uses a different mask.
///
/// The extension re-verifies this against the PHP it is loaded into at MINIT
/// (`hash_selftest`), which is the only check that can catch a platform we did
/// not anticipate.
#[must_use]
pub fn djbx33a(bytes: &[u8]) -> u64 {
    let mut hash: u64 = 5381;
    for &b in bytes {
        #[cfg(target_arch = "x86_64")]
        let v = b as i8 as i64 as u64;
        #[cfg(not(target_arch = "x86_64"))]
        let v = u64::from(b);
        hash = hash.wrapping_mul(33).wrapping_add(v);
    }
    hash | 0x8000_0000_0000_0000
}

/// Encode a ready-made `zend_string` (header + bytes + NUL, padded to 8) into
/// `out`. Returns the offset of the header within `out`.
pub fn push_zend_string(out: &mut Vec<u8>, bytes: &[u8]) -> u32 {
    while out.len() % 8 != 0 {
        out.push(0);
    }
    let off = out.len() as u32;
    let h = djbx33a(bytes);
    debug_assert_ne!(h, 0, "zend_string hash must never be zero");
    out.extend_from_slice(&ZS_REFCOUNT.to_ne_bytes());
    out.extend_from_slice(&ZS_TYPE_INFO_INTERNED.to_ne_bytes());
    out.extend_from_slice(&h.to_ne_bytes());
    out.extend_from_slice(&(bytes.len() as u64).to_ne_bytes());
    out.extend_from_slice(bytes);
    out.push(0); // zend_string is always NUL-terminated
    while out.len() % 8 != 0 {
        out.push(0);
    }
    off
}

/// Read back the `len` of a `zend_string` encoded by [`push_zend_string`].
/// Used for validation; the extension otherwise hands the address to PHP.
#[must_use]
pub fn zend_string_len(image: &[u8], off: usize) -> Option<usize> {
    let hdr_end = off.checked_add(ZS_HEADER_SIZE)?;
    if hdr_end > image.len() {
        return None;
    }
    let mut b = [0u8; 8];
    b.copy_from_slice(&image[off + ZS_OFF_LEN..off + ZS_OFF_LEN + 8]);
    let len = u64::from_ne_bytes(b) as usize;
    // header + bytes + the mandatory NUL must all be inside the image.
    if hdr_end.checked_add(len)?.checked_add(1)? > image.len() {
        return None;
    }
    Some(len)
}

#[cfg(test)]
mod tests {
    use super::*;

    /// These pin the algorithm without PHP in the loop. The authoritative check
    /// against the real engine is `hash_selftest` at MINIT.
    #[test]
    fn hash_is_never_zero_and_sets_high_bit() {
        for s in ["", "a", "title", "permissions", "\u{00e9}\u{00e9}"] {
            let h = djbx33a(s.as_bytes());
            assert_ne!(h, 0);
            assert_ne!(h & 0x8000_0000_0000_0000, 0, "high bit must be set for {s:?}");
        }
    }

    #[test]
    fn hash_matches_reference_implementation() {
        // Straight transcription of the C loop, independent of djbx33a's body.
        fn reference(bytes: &[u8]) -> u64 {
            let mut h: u64 = 5381;
            for &b in bytes {
                let v = if cfg!(target_arch = "x86_64") {
                    b as i8 as i64 as u64
                } else {
                    u64::from(b)
                };
                h = h
                    .wrapping_shl(5)
                    .wrapping_add(h)
                    .wrapping_add(v);
            }
            h | 0x8000_0000_0000_0000
        }
        for len in 0..40usize {
            let ascii: Vec<u8> = (0..len).map(|i| b'a' + (i % 26) as u8).collect();
            assert_eq!(djbx33a(&ascii), reference(&ascii), "ascii len {len}");
            let high: Vec<u8> = (0..len).map(|i| 0x80u8.wrapping_add(i as u8)).collect();
            assert_eq!(djbx33a(&high), reference(&high), "high bytes len {len}");
        }
        // Embedded NULs must be hashed, not treated as terminators.
        assert_eq!(djbx33a(b"a\0b"), reference(b"a\0b"));
        assert_ne!(djbx33a(b"a\0b"), djbx33a(b"a"));
    }

    #[test]
    fn zend_string_layout_constants() {
        assert_eq!(ZS_HEADER_SIZE, 24);
        assert_eq!(GC_STRING, 22);
        assert_eq!(GC_IMMUTABLE, 64);
        assert_eq!(ZS_TYPE_INFO_INTERNED, 22 | 64);
    }

    #[test]
    fn push_zend_string_round_trips() {
        let mut buf = Vec::new();
        let a = push_zend_string(&mut buf, b"title");
        let b = push_zend_string(&mut buf, b"");
        assert_eq!(a % 8, 0);
        assert_eq!(b % 8, 0);
        assert_eq!(zend_string_len(&buf, a as usize), Some(5));
        assert_eq!(zend_string_len(&buf, b as usize), Some(0));
        // bytes land right after the header, NUL-terminated
        let s = a as usize + ZS_OFF_VAL;
        assert_eq!(&buf[s..s + 5], b"title");
        assert_eq!(buf[s + 5], 0);
    }
}
