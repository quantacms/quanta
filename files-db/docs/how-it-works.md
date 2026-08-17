# How Files-DB works

The internals: what runs, what the bytes look like, and why a read needs no
lock, no syscall and no JSON parsing.

For the API you actually call, see [usage.md](usage.md). For the normative
specification, see [api-contract.md](api-contract.md).

---

## 1. The problem

Quanta stores every node as a **directory with `data*.json` inside it**. That is
the database, and it stays the database. The problem was never the storage — it
was the cost of reaching it.

Legacy Quanta answers "where is node X?" with `exec('find …')` over the docroot,
"which user has this email?" with `exec('grep -r …')`, and writes documents with
an unlocked `fopen('w+')` (a reader can observe a half-written file). On a large
tree under many PHP workers, those subprocess spawns dominate a request.

Reading a *known* document looked cheap, but it repeats syscalls and a JSON parse
on every request for a document that has not changed.

So the design goal is narrow: keep the files authoritative, and remove the
**searching**, the **coordinating**, and the **re-doing of work that has not
changed**.

---

## 2. Three processes, two channels

One Rust crate builds three artifacts:

| Artifact | What it is | Role |
|---|---|---|
| `quanta_db.so` | PHP extension (cdylib) | Reader. Originates writes. |
| `qdbd` | daemon binary | **The single writer** of the data segment. |
| `qdbstat` | monitor binary | Read-only observer. |

Every module except the PHP glue is deliberately PHP-free (std + libc only), so
the two binaries pull them in by source include rather than linking PHP. That is
how `qdbd` manages to write ready-made PHP `zend_string`s without linking PHP.

```
                    files on disk  (source of truth)
                          |                      ^
                  reads   |                      | writes: flock,
                 (boot,   |                      | tmp file + fsync
               reconcile) v                      | + atomic rename
                    +-----------+                |
      inotify ----> |   qdbd    |                |
     (external      |  daemon   |                |
      changes)      +-----------+                |
                          |                      |
             publishes    |                      |
        (single writer)   v                      |
        +--------------------------------+       |
        |  data.<epoch>.shm              |       |
        |  /dev/shm/quanta_db/<hash>/    |       |
        +--------------------------------+       |
                          |                      |
              mmap PROT_READ (no IPC at all)     |
                          v                      |
        +--------------------------------+       |
        |  PHP worker 1..N               | ------+
        |  quanta_db.so                  |
        +--------------------------------+
                          ^
                          |  AF_UNIX SOCK_STREAM  <base>/qdbd.sock
                          |  4-byte LE length prefix + JSON body
                          |  ops: upsert delete link unlink relink
                          |       move reindex ping
                          +--> qdbd applies, publishes, THEN acks
```

The two channels are split by direction, and that split is the whole design:

- **Reads use no IPC whatsoever.** A worker maps the segment read-only and probes
  it. The daemon is not in the read path and cannot slow it down.
- **Writes go to disk first**, then notify the daemon over the socket. The daemon
  acks only *after* the change is visible in shared memory — which is precisely
  what buys read-your-writes across separate PHP processes with no polling.

A third, smaller mapping — the **control arena** (`metrics.shm`) — is mapped
read-write by everyone and carries coherence, the active epoch and ~45 counters.
It is a control plane, not just statistics; see [§13](#13-the-control-arena).

### Where things live

Nothing is registered or discovered. All three processes independently derive the
same paths from `quanta_db.root` alone:

```
  quanta_db.root  (canonicalized)
        |
        +--> fnv1a  --> root_hash   (stamped into every segment header)
        |
        +--> DefaultHasher --> <16 hex>
                  |
   base = <system temp dir>/quanta_db/<16 hex>/
        |
        +-- qdbd.sock        write-notify socket
        +-- metrics.shm      control arena (4096 B, MAP_SHARED, RW)
        +-- locks/           per-node <name>.lock  (flock)
        +-- trashbin/        delete() destination   (override this)
        |
   shm_dir = /dev/shm/quanta_db/<16 hex>/     (tmpfs preferred)
        |                     falls back to base/shm
        +-- data.0.shm
        +-- data.1.shm       one file per epoch; only the newest is active
```

The `root_hash` in the segment header is what stops a worker configured for one
site from ever reading another site's segment: on mismatch the segment is
rejected and the worker degrades to fallback.

---

## 3. The data segment

`data.<epoch>.shm` is one file, three regions:

```
 offset 0
 +==========================================================+
 |  HEADER          HEADER_SIZE = 4096 bytes reserved       |
 |  (SegHeader is 136 bytes; the rest is append-only room)  |
 +==========================================================+  <- slots_off
 |  SLOT DIRECTORY  slot_count x u64, power of two          |
 |                  open addressing, linear probing         |
 +==========================================================+  <- arena_off
 |  ARENA           append-only records, each 8-aligned     |
 |                                                          |
 |  [rec][rec][rec][rec]..........                          |
 |                          ^                               |
 |                          arena_next  (bump pointer)      |
 |                                                          |
 |                  free (sparse on tmpfs until touched)    |
 +==========================================================+  <- seg_size
```

`SegHeader`, in declaration order — the fields a reader validates before
trusting anything:

```
   0  magic          AtomicU64   "QDBDAT1\0"
   8  layout_version u32         = 2  (bumped when images were added)
  12  _pad0          u32
  16  epoch          u64         must match the filename
  24  seg_size       u64         must match the real file size
  32  slot_count     u64         must be a power of two
  40  slots_off      u64         >= HEADER_SIZE
  48  arena_off      u64         after the slot directory
  56  arena_next     AtomicU64   bump pointer (the only mutable geometry)
  64  ready          AtomicU64   0 while filling, 1 (Release) when published
  72  node_count     AtomicU64
  80  link_count     AtomicU64
  88  tombstone_count AtomicU64
  96  dead_bytes     AtomicU64   superseded + tombstoned (compaction input)
 104  doc_bytes      AtomicU64
 112  root_hash      u64         rejects a segment built for another root
 120  daemon_pid     u64
 128  img_bytes      AtomicU64   appended in layout 2
```

The plain (non-atomic) fields are written *before* `magic` is Release-stored and
never change afterwards, so a reader that sees the magic sees valid geometry.

Three independent guards make version skew safe rather than silently wrong:
`magic`, `layout_version`, and `root_hash`. A `.so` and a `qdbd` built from
different commits simply refuse each other's segment, and the extension degrades
to fallback mode.

### The slot word

Each slot is a single `u64` packing a hash tag next to an arena offset:

```
  63                48 47                                        0
  +-------------------+------------------------------------------+
  |   16-bit tag      |        48-bit arena offset               |
  |  (top bits of the |     (byte position of the record)        |
  |   FNV-1a hash)    |                                          |
  +-------------------+------------------------------------------+

  slot == 0  =>  empty
```

The tag lets a probe reject a non-matching slot without dereferencing the arena
at all — one load, one compare, no cache miss on the record itself.

### A record

```
  0  rec_len       u32    total bytes, including padding to 8
  4  flags         u16    REC_TOMBSTONE = 1
  6  lang_count    u16
  8  generation    u64    the cache invalidator
 16  name_hash     u64    FNV-1a of the name
 24  mtime         i64
 32  name_len      u16
 34  path_len      u16
 36  father_len    u16    0 = root-level node
 38  child_count   u16
 40  inlink_count  u16
 42  _pad          u16
 ------------------------ REC_FIXED = 44
 44  name bytes | rel_path bytes | father bytes
     children:  child_count x u16   [bit15 = is_link | bits0-14 = len]
                then all the child name bytes
     inlinks:   inlink_count x u16 len
                then all the container name bytes
     langs:     lang_count x {  u16 lang_len   u16 flags
                                u32 doc_len
                                i64 doc_mtime  i64 doc_size
                                u32 img_off    u32 img_len  }   (32 B each)
                then per lang:  lang bytes | doc bytes
                then, each 8-aligned:  the pre-decoded images
     zero-pad to a multiple of 8
```

Per-language flags: `LANG_CORRUPT = 1` (the file exists but does not parse — a
reader must raise `CORRUPT_JSON`, not report "no document") and
`LANG_HAS_IMAGE = 2`.

`img_off` is measured **from the start of the record**, not from the document
stream. Records begin at 8-aligned arena offsets and each image is individually
padded to 8, so every `zend_string` inside an image is 8-aligned *in the
mapping* — which is what makes it legal to point a PHP zval at one.

---

## 4. Lookup

```
   name
     |
     v
  fnv1a(name) ---> hash
     |
     +--> tag = hash & 0xffff_0000_0000_0000
     |
     v
   i = hash & (slot_count - 1)
     |
  +->+
  |  |
  |  v
  |  slot = slots[i].load(Acquire)
  |     |
  |     +-- slot == 0 ----------------------> Lookup::Absent
  |     |
  |     +-- slot_tag(slot) != tag ---+
  |     |                            |
  |     |                            +--> i = (i + 1) & mask  --+
  |     |                                                       |
  |     +-- tag matches                                         |
  |            |                                                |
  |            v                                                |
  |     RecordView::parse(arena + slot_off(slot))               |
  |            |                                                |
  |            +-- None (geometry inconsistent) --> Invalid ----|--> fallback
  |            |                                                |
  |            +-- rec.name_hash != hash  OR                    |
  |            |   rec.name() != name        (tag collision) ---+
  |            |
  |            +-- full match --> Lookup::Found(rec)
  |                                  |
  +----------------------------------+   flags & REC_TOMBSTONE?
                                          yes -> definitively absent
                                          no  -> the node
```

Two properties fall out of this and matter for correctness:

- The final check is a **full hash + full name comparison**, so a 16-bit tag
  collision is a probe continuation, never a wrong answer.
- `RecordView::parse` walks the whole variable-section geometry with checked
  arithmetic and returns `None` on any inconsistency. A torn or garbage slot can
  therefore only produce a *miss* (counted as `shm_invalid`, answered by falling
  back), never a fault.

**Tombstones** keep probe chains intact *and* encode "definitively gone". They
are why a delete does not break the linear-probe chain of unrelated names, and
why a coherent daemon can answer "absent" without touching the disk.

---

## 5. Why readers never lock and never tear

There is no seqlock here, no RCU, no epoch reclamation. Four invariants make a
plain `Acquire` load sufficient.

```
  WRITER (qdbd, single-threaded)        READER (any PHP worker)
  ------------------------------        -----------------------

  1. bump arena_next, claim space
  2. memcpy the record bytes
     (invisible: nothing points here)

  3. slots[i].store(off, Release)  ---> slots[i].load(Acquire)
        ^                                     |
        | everything written before           | everything written before
        | this store is visible to  ..........| the matching store is
        | anyone who reads it                 | visible here
                                              v
                                        4. parse + bounds-check + verify name
```

1. **Records are immutable once published.** The writer claims fresh arena space,
   writes the bytes, and only then publishes with a single Release store. An
   update writes a *new* record and flips one slot; the superseded bytes are
   never touched again, they just count into `dead_bytes`.
2. **Slots only ever move between valid published values** — empty → record,
   record A → record B, record → tombstone. Never back to garbage.
3. **A segment file is never truncated or recycled.** Growth and compaction write
   a *new* file and flip the published epoch. The unlinked predecessor stays
   mapped and valid for any reader still holding it.
4. **Every access is bounds-checked** against the fixed `seg_size`, and every
   record is verified by full hash + name comparison.

`arena_next`, the counters and all metrics use `Relaxed` — they are diagnostics
and allocation, not publication. Only the slot directory, `magic`, `ready` and
the active epoch carry Release/Acquire ordering.

---

## 6. The pre-decoded image

`qdbd` already parses every `data*.json` — it has to, in order to detect
corruption. So it also stores a **second representation** of the document beside
the raw bytes: a flat, tagged, pointer-free encoding that a worker can turn into
zvals in one linear pass.

```
  image header, 16 bytes
   0  magic      u32   "QDBI"
   4  version    u16
   6  flags      u16   bit 0 = string entries are real zend_strings
   8  node_count u32
  12  root_off   u32   ---------------+
                                      |
  value node, 16 bytes, 8-aligned     |
   0  tag     u8   <-----------------+
   1  (pad)
   4  count   u32
   8  payload u64

  tags:  0 NULL   1 FALSE   2 TRUE   3 LONG
         4 DOUBLE 5 STR     6 LIST   7 MAP

  LIST  ->  count x u32 node_off
  MAP   ->  count x { u32 key_str_off, u32 val_node_off }
  STR   ->  payload points at a complete zend_string:

            +--------+--------+--------+------------------+
            |  gc    |   h    |  len   |  val[] + NUL     |
            |  8 B   |  8 B   |  8 B   |                  |
            +--------+--------+--------+------------------+
             ^ refcount/type   ^ precomputed DJBX33A hash
               = INTERNED        (never zero -- see below)
```

**No pointers anywhere.** Every reference is an offset from the start of the
image, so the identical bytes are valid at whatever address each worker happened
to map the segment at. Strings are deduplicated within a document, which
collapses the repeated keys of same-shaped objects.

Materializing a document is then a walk, not a parse — no tokenizing, no number
parsing, no UTF-8 revalidation, and with `zero_copy=on` no string allocation or
copying at all:

```
  image in the read-only mapping     PHP heap
  ------------------------------     --------

  MAP node ---+                      zend_array
              |                       +-- key "title" --> interned
              |                       |                   zend_string
              +-- key_str_off --------+                   (COPIED into
              |                       |                    PHP's table)
              |                       |
              +-- val_node_off --+    +-- val zval ---.
                                 |                     \
                            STR node                    \
                                 |                       `-> points INTO
                                 +-- zend_string ----------> the mapping
                                                             (no copy)
```

Map **keys** are the one thing that must be copied into PHP's interned string
table. `zend_std_read_property` validates its inline property cache by *pointer
identity* against the compile-time interned name, so a key that merely has equal
bytes is not enough.

### Three safety properties

This hands the Zend engine pointers into a read-only shared mapping, so it is
guarded three ways.

**ABI guard.** At MINIT the extension compares `zend_string`'s field offsets and
flag values against the PHP it was actually loaded into, *and* runs a live hash
self-test: build strings with `h = 0`, let the real engine hash them as array
keys, read `h` back and compare against its own DJBX33A port — over a corpus
covering lengths 0–17, bytes ≥ 0x80 and embedded NULs. Any mismatch disables the
image path only; reads still work, they just parse, and `stats()['image']` reads
`abi-mismatch`. **MINIT never fails.** A wrong hash would produce a key
`foreach` can see but `array_key_exists()` denies, and a wrong layout would be a
crash rather than a wrong answer — neither is worth risking to save a parse.

The precomputed hash is a *safety* requirement, not an optimisation:
`zend_string_hash_val()` writes the hash back into the string when it finds zero,
and writing into a `PROT_READ` mapping is a segfault.

**Lifetime.** A segment whose bytes a live zval points at is *pinned* for the
request and released in `post_deactivate` — deliberately not in RSHUTDOWN, which
runs *before* `zend_deactivate()` destroys the object store and would leave
`zend_hash_destroy` reading `GC_FLAGS(key)` out of unmapped memory. At most 4
epochs are pinned per request; past that the extension copies instead.

**Kill switch.** `quanta_db.zero_copy=0` selects the copying materializer and
`quanta_db.image=0` disables the image entirely. Both are runtime ini flags and
results are byte-identical either way — the conformance suite runs all four
combinations.

---

## 7. The read path

```
                       QuantaDb::get($name)
                                |
                                v
                     metrics::index_coherent()?
                     (flag set AND heartbeat <= 5s old)
                          |            |
                       no |            | yes
                          |            v
                          |     data_epoch  ==  mapped epoch?
                          |          |              |
                          |       no |              | yes
                          |          v              |
                          |    remap segment        |
                          |    (up to 3 retries)    |
                          |          |              |
                          |          +--------------+
                          |                 |
                          |                 v
                          |          hash probe (section 4)
                          |             |          |
                          |       found |          | absent / tombstone
                          |             |          |
                          |             |          v
                          |             |    AUTHORITATIVE ABSENCE
                          |             |    return null, touch no disk
                          |             v
                          |      record has an image for this lang?
                          |          |                    |
                          |      yes |                    | no
                          |          v                    v
                          |    materialize          parse cache hit?
                          |    (no parsing)         (keyed by generation)
                          |          |               |          |
                          |          |          yes  |          | no
                          |          |               |          v
                          |          |               |     parse the raw
                          |          |               |     bytes, cache it
                          |          |               |          |
                          |          +---------------+----------+
                          |                        |
                          v                        v
                    FALLBACK MODE              the document
                    walk snapshot (2s TTL)
                    + direct file read
                    + negative cache (30s)
```

While the daemon is coherent, the shaded-out left branch never runs: a read is a
probe plus a walk of already-decoded bytes. No `stat`, no `open`, no
`json_decode`.

**Authoritative absence** is the semantically important branch. A miss under a
coherent daemon means the node genuinely does not exist — the daemon's inotify
and reconcile already reflect any out-of-band create — so the answer comes back
with no filesystem access. That is what lets `Environment::nodePath()` skip its
legacy `exec find` entirely for names that do not exist.

---

## 8. The write path

```
  PHP worker                          qdbd                    disk
  ----------                          ----                    ----

  1 ensure_valid_name
      rejects "" . .. / \ NUL

  2 lock::acquire(name) -------------------------------> locks/<name>.lock
      flock(LOCK_EX|LOCK_NB), retried every 5 ms          flock held
      until lock_timeout_ms -> LOCK_TIMEOUT

  3 resolve_node -- existence / create-intent check

  4 mutate the filesystem  ----------------------------> the atomic primitive
      put:    tmp file -> fsync -> rename()                per operation
      create: mkdir()            (atomic name reservation)
      delete: rename into trashbin/<ts>/<name>
      link:   symlink(absolute target path)
      relink: rename() of the symlink
      move:   rename(dir), then repoint each inbound
              link (temp symlink + rename over)

  5 notify ----- {"op":"upsert",...} ----> apply
                                             |
                                          re-read the node
                                          from disk (files
                                          are the truth)
                                             |
                                          publish to the
                                          segment (Release)
                                             |
      <-------------- {"ok":true,...} ----- ACK
                                          (only now)

  6 local cache maintenance, metrics
  7 release the lock (Drop)
```

Step 5's ordering is the guarantee: the daemon publishes *before* it acks, so by
the time `put()` returns, **every other process on the pod already sees the new
value**. No polling, no generation waiting.

### When the ack does not come

```
   notify_daemon()
        |
        +-- daemon not coherent?  -> don't even try. Reads are already
        |                            coming from the filesystem.
        |
        +-- coherent, ack within write_ack_timeout_ms (250 ms)?
        |        yes -> done
        |
        +-- no ack / socket error
                 |
                 +-- ipc::reset()  (drop the connection)
                 +-- metrics::set_coherent(false)   <-- POISONS THE POD
                 +-- return: the write is already durable on disk
```

Poisoning is deliberate and pod-wide. If the daemon is coherent, readers trust
its segment — so a write that fails to reach it *must* flip everyone to fallback,
or they would keep serving stale data behind a heartbeat that still looks fresh.
The daemon re-asserts coherence on its next loop tick once it recovers.

**Writes are deliberately slower than legacy.** The lock, the fsync + atomic
rename, and the ack are the price of atomicity and read-your-writes; legacy's
faster write is faster because it is unsafe.

---

## 9. The daemon

### Boot

The ordering here is load-bearing at three separate points.

```
   1  parse args, resolve config (env only -- no PHP, no ini)
   2  map metrics.shm, publish daemon_pid
   3  install SIGTERM/SIGINT handlers; ignore SIGPIPE
        (a client dying mid-ack must not kill the daemon)
   4  inotify_init, set O_NONBLOCK
   5  DELETE every stale data.*.shm            <-- crash recovery
   6  boot_epoch = data_epoch + 1              <-- never reuse a number
   7  add_watches(root)                        <-- BEFORE the scan
   8  model::build_from_disk()                     (see below)
   9  publish_full() -> real epoch, ready=1, set_data_epoch
  10  bind the unix socket                     <-- LAST
        (a successful connect must imply "serving")
  11  set_heartbeat(); set_coherent(true)
  12  poll loop {inotify, listener, clients}, 1000 ms tick
```

**Watch-before-scan** (step 7 before 8) closes a real window: watching after
reading means a node created between the read and the watch is invisible until
the next reconcile. The same ordering is repeated for every newly created
directory, where the bug was sharper still — reading first could catch a
`mkdir` + `file_put_contents` at zero bytes, latch the document as
`LANG_CORRUPT`, and throw `CORRUPT_JSON` on every read until the next reconcile.

### The event loop

Watch mask: `CREATE | DELETE | MOVED_FROM | MOVED_TO | CLOSE_WRITE |
DELETE_SELF | MOVE_SELF | ONLYDIR`. `ONLYDIR` constrains only *what may be
watched*; events inside still cover files, which is required now that documents
live in shared memory — a `data.json` overwrite must invalidate the segment
immediately, not 60 seconds later.

| Event | Handling |
|---|---|
| `Q_OVERFLOW` | full `reconcile()`, reset the resync timer |
| `IGNORED` | drop the watch descriptor |
| dir `CREATE` / `MOVED_TO` | watch the new dir **first**, then walk and upsert the subtree |
| dir `MOVED_FROM` | **deferred** — end of batch, then a grace period; see below |
| dir `DELETE` | remove the node and its subtree |
| `data*.json` write/move/delete | re-read that document (no-op if `mtime`+`size` are unchanged) |
| other create/delete/move | rescan the directory's membership — probably a symlink change |

The daemon's own UDS-acked writes fire inotify events too, so the document
handler stats the file and compares `mtime` + `size` against its model first;
unchanged means return.

**The `MOVED_FROM` deferral** deserves its own note, because the naive handling
was subtly wrong. Treating `MOVED_FROM` as a delete turns every in-tree rename
into delete-then-add — and a lookup landing in that gap gets an **authoritative**
"no such node" for a node that existed the entire time. Callers act on that
answer (`Environment::nodePath` skips its legacy `find` on exactly that verdict),
so the node briefly vanishes from the site. Deferring until the end of the event
batch lets the matching `MOVED_TO` — same `rename()`, normally the same `read()`
— re-point the model first.

*Normally* is not *always*, and the gap between the two is the whole problem.
The kernel queues `MOVED_FROM` and `MOVED_TO` one after the other rather than as
a pair, so a daemon that drains the queue in between gets a batch holding only
the `MOVED_FROM` — and end-of-batch is then just as wrong as immediate, because
the old path is already gone and the model still points at it. So a `MOVED_FROM`
that nothing has accounted for by the end of its batch is *held* (50 ms,
`MOVED_GRACE`) rather than acted on, and re-checked against the model when the
grace runs out; by then the counterpart event or the writer's own UDS `move` has
re-pointed the node, and there is nothing to do. What is still filed at a path
that no longer exists genuinely left the tree. The poll timeout shortens to the
grace while anything is held, so an idle loop does not keep a departed node
alive for its full second.

### Reconcile

Every 60 seconds (`--resync-secs`) as a safety net beneath inotify:

```
  one walk_dedup of the whole root
        |
        +-- 1. tombstone model nodes the walk no longer found
        |
        +-- 2. replace the link edge set wholesale; both endpoints of
        |      every symmetric difference are marked changed
        |
        +-- 3. per walked node, drift =  new node
        |                             OR rel_path changed
        |                             OR docs drifted (stat mismatch,
        |                                language appeared/vanished)
        |                             OR children list changed
        |      -> reload + assign a fresh generation
        |
        +-- 4. rebuild inlinks, republish changed nodes, refresh counts,
               prune dead watches, re-add watches, maybe_compact
```

An idle reconcile costs one walk plus stats, and **unchanged nodes keep their
generations** — so every worker's parse cache stays valid across it.

If the kernel watch limit (`fs.inotify.max_user_watches`) is hit, the daemon
prints a hint, sets `degraded`, and runs reconcile-only. Correct, just laggier.

`model::build_from_disk` — the full walk — runs on boot and on `reindex()`. It
is the *only* bulk filesystem read in the system, and it is never on a serving
path.

---

## 10. Growth and compaction

Records are never freed in place, so the arena only grows. Reclamation happens by
writing a whole new segment.

```
  should_compact()
     used_slots * 2 >= slot_count            (50% load factor)
  OR dead_bytes > max(arena_used / 4, 4 MiB)

  checked after every mutation batch
        |
        v
  publish_full()
        |
        +-- epoch + 1
        +-- slot_count_for(nodes) = max(nodes*4, 8192).next_power_of_two()
        +-- seg_size_for(live, slots, budget) = 4096-aligned
        |     max(4096 + slots*8 + live*2 + 1 MiB, shm_size_mb)
        +-- encode every node from the in-RAM model
        +-- ready = 1  (Release)
        +-- metrics::set_data_epoch(epoch)   <-- the flip
        +-- unlink anything older than epoch-1


   epoch N           epoch N+1
   +--------+       +----------+
   | data.N |       | data.N+1 |  <-- data_epoch now points here
   +--------+       +----------+
       ^                  ^
       |                  |
   readers still      readers remap
   mapping it         on their next call
   keep working       (up to 3 retries)
   (never truncated,
    never recycled)
```

`used_slots` never decreases — a tombstone keeps its slot occupied — so the load
factor counts tombstones. That is intentional: it is what eventually drives
compaction under a delete-heavy workload.

An `ArenaFull` / `SlotsFull` error from a write also forces `publish_full`
immediately, and no retry is needed because the fresh segment is built from the
model, which already contains the node.

The `shm_size_mb` budget (default 64) is a floor, not an allocation: the file is
sparse on tmpfs, so untouched pages cost nothing.

Documents larger than `image_max_doc_kb` (default 256 KB) are not imaged. The
image roughly doubles a document's footprint, and a very large body is dominated
by moving bytes rather than by parsing them, so the trade stops paying. Images
are built **once**, when the document is loaded — never during re-encoding, which
happens constantly as children and links change.

---

## 11. Coherence and fallback

```
                 qdbd writes a heartbeat + a flag into metrics.shm
                              every loop tick (1 s)

     +------------------------------------------------------------+
     |                                                            |
     v                                                            |
  COHERENT                                                        |
  watch_coherent = 1 AND heartbeat <= 5 s old                     |
     |                                                            |
     |  * reads come from shared memory                           |
     |  * a miss is a DEFINITIVE absence                          |
     |  * writes must reach the daemon                            |
     |  * QuantaDb::coherent() === true                           |
     |                                                            |
     +-- daemon killed (heartbeat goes stale after 5 s) ----+      |
     +-- daemon exits cleanly (set_coherent(false)) --------+      |
     +-- a write ack times out (extension poisons it) ------+      |
     +-- quanta_db.metrics = off (arena never created) -----+      |
                                                           |      |
                                                           v      |
                                                       FALLBACK   |
                                                           |      |
       * per-process filesystem walk snapshot (2 s TTL)    |      |
       * direct file reads                                 |      |
       * negative cache for proven-absent names (30 s)     |      |
       * a miss triggers a self-heal walk                  |      |
       * QuantaDb::coherent() === false                    |      |
                                                           |      |
                    daemon recovers and re-asserts --------+------+
```

Fallback is exactly the legacy profile: nothing is faster, nothing breaks. The
app is always correct; the daemon only removes work.

Because everything in shared memory is *derived* from the files, it is always
rebuildable — `QuantaDb::reindex()`, or simply restarting `qdbd`. There is no
migration, no separate schema, and nothing to lose if a segment is thrown away.

### Crash recovery

| Failure | Detected by | Result |
|---|---|---|
| daemon killed | heartbeat older than 5 s | every worker flips to fallback |
| daemon exits cleanly | explicit `set_coherent(false)` + socket unlink | immediate fallback, no 5 s wait |
| daemon alive but unresponsive | write ack timeout | pod-wide poison; re-asserted on recovery |
| segments left by a crashed daemon | boot sweep deletes `data.*.shm`; `boot_epoch = data_epoch + 1` | starts clean, never reuses an epoch |
| worker dies holding a node lock | `flock` is released by the kernel | no node ever wedges |
| reader racing a compaction | epoch mismatch / missing file | 3 remap retries, then fallback |
| worker still mapping an unlinked segment | segments are never truncated or recycled | the mapping stays valid |
| torn or garbage record bytes | `RecordView::parse` returns `None` | counted `shm_invalid`, that call falls back |
| damaged image | image validation fails | counted `img_invalid`, parse the raw bytes |
| `.so` / `qdbd` version skew | `layout_version`, metrics `VERSION` | segment rejected → fallback; stale arena rebuilt |
| wrong data root | `root_hash` mismatch | segment rejected |
| crash mid-`move` | — | dangling symlinks, repaired by `reindex()` |

---

## 12. Per-process caches

All four are thread-local to a PHP worker. None of them is the design point — the
segment is. They exist to avoid re-doing work inside one process.

| Cache | Contents | Bound | Eviction | Active in |
|---|---|---|---|---|
| `DOCS` | decoded documents, keyed by `(name, lang)` + validated by `generation` | 2048 | clear the whole map on overflow | both modes |
| `NEG` | names proven absent | 8192 | clear-all, plus `neg_cache_ms` TTL (30 s) | fallback only |
| `SNAP` | one filesystem walk: `name → (path, father)` + link edges | one snapshot | 2 s TTL, rebuilt on any miss | fallback only |
| `GENS` | generations of nodes this process wrote | unbounded | — | fallback only |

The parse cache is validated by **generation, not mtime** — mtime's 1-second
granularity cannot distinguish two same-second writes. A stale generation is a
miss; name and lang are re-compared on every hit, so a hash collision is a miss
rather than a wrong document.

Notably the parse cache is *not* used for fallback-mode file reads: there, files
are the source of truth and there is no generation to trust.

---

## 13. The control arena

`metrics.shm` is one 4096-byte page, mapped `MAP_SHARED` read-write by every
worker and the daemon, read-only by `qdbstat`. Its header carries a magic and a
version; a version mismatch causes it to be zeroed and rebuilt, and initialization
races are settled with `flock` while `magic` is published last.

It is misleadingly named — four of its fields are a **control plane**, not
statistics:

| Field | Written by | Read by | Purpose |
|---|---|---|---|
| `data_epoch` | daemon (Release) | workers (Acquire) | which segment file is active |
| `watch_coherent` | daemon, and extension on ack failure | workers | is shared memory authoritative |
| `watch_heartbeat_unix` | daemon, every tick | workers | staleness bound (5 s) |
| `gen_counter` | everyone | everyone | cross-process monotonic generation allocator |

That is why `quanta_db.metrics=off` forces permanent fallback: with no arena
there is nowhere to publish coherence, so no worker can ever trust the segment.

The remaining ~45 fields are `Relaxed` counters bumped on the hot paths — reads,
writes, latency sums and peaks, the read-path mix, lookup resolution, lock
contention, per-op query counts, and error tallies. Every one is a no-op when the
arena is not mapped.

`qdbstat` maps the arena read-only, follows `data_epoch` to the live segment, and
renders a varnishstat-style dashboard: a health verdict (Healthy / Degraded /
Fallback / Unknown) over sections for Daemon, Storage, Reads, Lookups, Queries,
Writes and Health. `QuantaDb::stats()` exposes the same counters to PHP.

Every counter feeding that verdict is cumulative for the life of the pod, so
none of them may be graded with `> 0`: a single blip during the seconds between
php-fpm accepting traffic and the daemon publishing its first segment would
otherwise pin a long-since healthy pod to DEGRADED forever, which is how a
dashboard teaches people to ignore it. Counters on a high-volume path are graded
as a **share** of that path (`fallback_reads` against all reads), which decays on
its own. `uds_failures` cannot be: its denominator is notify *attempts*, and a
pod serving 100k reads may make only a handful of writes, so one failure against
eight notifies reads as 11% and never decays. It is graded on **recency**
(`uds_failure_unix`, a 60 s window) instead — which loses nothing, because every
notify failure poisons coherence at the call site, so an episode that is still
live already shows as the stronger FALLBACK verdict.

Counters are per-pod — the arena and segment live in the pod's tmpfs — and reset
when the extension is redeployed. There is no cluster-aggregated view.

---

## 14. Performance

The gain is structural, not micro-optimisation.

| Operation | Legacy cost | Now |
|---|---|---|
| Resolve name → path (cold) | `exec find` over the docroot: process spawn + full tree walk | one hash probe |
| Resolve name → path (warm) | static array / shard symlink | **unchanged** — extension not consulted |
| User lookup by field | `exec grep -r` over `_users` | in-memory `find` with `where` |
| Read a known document | `open` + `read` + `json_decode` | probe + image walk |
| Write a document | unlocked `fopen('w+')`, torn reads possible | flock + fsync + atomic rename + ack |
| Link / unlink | `symlink()` + separate bookkeeping | one atomic call |

The decisive win is **path resolution on a cold name**: it goes from "walk the
tree" to "probe a hash", so it stops scaling with the size of the site. Since
most requests touch some cold names, that is where the request-level gain comes
from.

Measured on `php:8.2-fpm` against a live daemon (`tests/bench/probe.php`, 20 000
iterations), per `Node::loadJSON`-equivalent read:

| document | legacy `fgc`+`json_decode` | image off | image on | image + zero-copy |
|---|---|---|---|---|
| 48 B | 6.53 µs | 0.36 µs | 0.25 µs | **0.24 µs** (27×) |
| 437 B | 7.19 µs | 0.64 µs | 0.48 µs | **0.44 µs** (16×) |
| 205 KB | 129.6 µs | 3.22 µs | 3.03 µs | **0.46 µs** (279×) |

Zero-copy is what makes the large document flat — the 205 KB body is never
copied. For small documents the image itself is the bigger half.

> **A cautionary note about measuring this.** This section used to claim the
> opposite: that document reads were left on the legacy path because
> `QuantaDb::get()` measured **2–4× slower**. That measurement was taken with
> `run-bench.sh` in **fallback mode** — the script never set `QDB_MODE`, so there
> was no daemon and no shared memory, and it was comparing two ways of reading
> the same file, one of them across an FFI boundary. `run-bench.sh` now defaults
> to daemon mode. **Always check `stats()['mode']` is `shm` before trusting a
> benchmark.**

The corrected lesson: shared memory wins where legacy had to **search** (spawn a
process, walk a tree), **coordinate** (lock, publish atomically), *or* **re-do
work every request** (syscalls and parsing for a document that has not changed).

```bash
docker run --rm quanta-db sh /ext/tests/run-bench.sh
# sizing: -e QDB_BENCH_N=1000 -e QDB_BENCH_WRITES=500
```

`tests/bench/bench.php` re-implements the legacy access patterns verbatim,
asserts both paths give identical answers on one shared tree, then times the same
operations side by side. Note it benchmarks the whole API, including paths Quanta
does not currently use.

---

## 15. Where to look in the source

| Concern | File |
|---|---|
| PHP-facing methods, materialization, pinning, ABI guard | `src/lib.rs` |
| Segment layout, records, slots, compaction | `src/shm.rs` |
| Pre-decoded document image | `src/image.rs` |
| `zend_string` layout constants + DJBX33A port | `src/php_abi.rs` |
| Control arena and counters | `src/metrics.rs` |
| Socket framing and message constructors | `src/ipc.rs` |
| Filesystem walk, document IO, trashbin | `src/store.rs` |
| In-RAM node model (daemon side) | `src/model.rs` |
| Config resolution | `src/config.rs` |
| Path derivation | `src/paths.rs` |
| Per-node `flock` | `src/lock.rs` |
| The daemon | `src/bin/qdbd.rs` |
| The monitor | `src/bin/qdbstat.rs` |
