#!/usr/bin/env python3
"""Model the segment cost of a document corpus without running the daemon.

Replicates `src/image.rs` (Encoder) and `src/php_abi.rs` (push_zend_string)
byte for byte, so it answers "what would dropping the raw JSON actually save?"
against a real tree without deploying anything.

Usage:  image-size.py <docroot> [--per-doc] [--dedup]

  (default)   raw vs image totals, and the saving from an image-only segment
  --per-doc   per-document ratios, worst first (find the pathological shapes)
  --dedup     what a SEGMENT-WIDE string table would save over today's
              per-document interning

The image cost is driven by the COUNT of distinct strings, not by bytes: every
string carries a 24-byte zend_string header padded to 8. A document of many
short fields images at 3-5x its JSON; one long text body images at ~1.1x.
"""
import json, glob, os, sys

HEADER_SIZE = 16   # image.rs: HEADER_SIZE
NODE_SIZE = 16     # image.rs: NODE_SIZE
ZS_OFF_VAL = 24    # php_abi.rs: refcount u32 + type_info u32 + h u64 + len u64


class Encoder:
    """Mirrors image.rs Encoder: node allocation, per-document string interning."""

    def __init__(self):
        self.n = HEADER_SIZE
        self.strings = {}
        self.nodes = 0
        self.string_bytes = 0
        self.table_bytes = 0

    def _align(self, a):
        self.n += -self.n % a

    def intern(self, s):
        if s in self.strings:
            return
        self._align(8)
        self.strings[s] = self.n
        before = self.n
        self.n += ZS_OFF_VAL + len(s.encode("utf-8")) + 1  # header + bytes + NUL
        self._align(8)
        self.string_bytes += self.n - before

    def value(self, v):
        self._align(8)
        self.n += NODE_SIZE
        self.nodes += 1
        if isinstance(v, str):
            self.intern(v)
        elif isinstance(v, list):
            for x in v:
                self.value(x)
            self._align(4)
            self.n += 4 * len(v)          # element offset table
            self.table_bytes += 4 * len(v)
        elif isinstance(v, dict):
            for k, x in v.items():
                self.intern(k)
                self.value(x)
            self._align(4)
            self.n += 8 * len(v)          # (key_off, val_off) pair table
            self.table_bytes += 8 * len(v)
        # null/bool/int/float live entirely in the node's payload word


def image_size(v):
    e = Encoder()
    e.value(v)
    return e


def zs_cost(s):
    n = ZS_OFF_VAL + len(s.encode("utf-8")) + 1
    return n + (-n % 8)


def docs(root):
    for p in sorted(glob.glob(os.path.join(root, "**", "data*.json"), recursive=True)):
        try:
            raw = open(p, "rb").read()
            yield p, raw, json.loads(raw)
        except Exception:
            continue          # corrupt documents are never imaged


def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    flags = {a for a in sys.argv[1:] if a.startswith("--")}
    root = args[0] if args else "."

    rows, tot_raw, tot_img = [], 0, 0
    for p, raw, v in docs(root):
        e = image_size(v)
        tot_raw += len(raw)
        tot_img += e.n
        rows.append((e.n / max(len(raw), 1), len(raw), e, os.path.relpath(p, root)))

    if not rows:
        print(f"no parseable data*.json under {root}")
        return

    print(f"docs                 : {len(rows):,}")
    print(f"raw   total          : {tot_raw:,} B")
    print(f"image total          : {tot_img:,} B   ({tot_img / tot_raw:.2f}x raw)")
    print(f"segment doc payload  : {tot_raw + tot_img:,} B   (raw + image, today)")
    print(f"image-only would save: {tot_raw:,} B "
          f"({100 * tot_raw / (tot_raw + tot_img):.1f}% of doc payload)")

    if "--per-doc" in flags:
        print("\nper-document, worst ratio first:")
        print(f"  {'ratio':>7} {'raw':>8} {'image':>9} {'nodes':>6} {'strs':>5} {'strB':>8}  path")
        for r, raw, e, p in sorted(rows, reverse=True):
            print(f"  {r:6.2f}x {raw:8,} {e.n:9,} {e.nodes:6} "
                  f"{len(e.strings):5} {e.string_bytes:8,}  {p}")

    if "--dedup" in flags:
        per_doc_bytes = per_doc_count = 0
        seg = {}
        keys = set()

        def walk(v, seen):
            if isinstance(v, str):
                seen.add(v)
            elif isinstance(v, list):
                for x in v:
                    walk(x, seen)
            elif isinstance(v, dict):
                for k, x in v.items():
                    seen.add(k)
                    keys.add(k)
                    walk(x, seen)

        for _, _, v in docs(root):
            seen = set()
            walk(v, seen)
            per_doc_count += len(seen)
            per_doc_bytes += sum(zs_cost(s) for s in seen)
            for s in seen:
                seg[s] = zs_cost(s)
        seg_bytes = sum(seg.values())
        print(f"\nper-document string tables (today): {per_doc_count:,} entries, "
              f"{per_doc_bytes:,} B")
        print(f"segment-wide string table         : {len(seg):,} entries, {seg_bytes:,} B")
        print(f"saving                            : {per_doc_bytes - seg_bytes:,} B "
              f"({100 * (per_doc_bytes - seg_bytes) / per_doc_bytes:.1f}% of string bytes)")
        print(f"distinct object keys in the tree  : {len(keys):,}")


if __name__ == "__main__":
    main()
