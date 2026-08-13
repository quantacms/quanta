//! `qdbstat` — a varnishstat-style live monitor for the quanta_db extension.
//!
//! Reads two derived data sources for a given data root:
//!   * the shared-memory counter arena (`<base>/metrics.shm`) — op rates,
//!     read/write latency (avg + peak), per-op query counts, lock contention,
//!     daemon coherence and the active segment epoch, aggregated across all
//!     workers of one pod;
//!   * the active data segment (`<shm_dir>/data.<epoch>.shm`, mapped read-only)
//!     — node/link cardinality, document bytes, arena usage.
//!
//! Output is a grouped, color dashboard headed by a health verdict
//! (HEALTHY / DEGRADED / FALLBACK) with Daemon / Storage / Reads / Lookups /
//! Queries / Writes / Health sections; color auto-disables when stdout is not a
//! terminal, when `NO_COLOR` is set, or with `--no-color`. `--json` emits a flat
//! snapshot (existing keys stable; derived keys appended).
//!
//! It links neither PHP nor the cdylib; the arena/segment layouts + path
//! derivation are shared with the extension by source include.
#![allow(dead_code)]
// The `--json` snapshot is a large `serde_json::json!` literal.
#![recursion_limit = "256"]

#[path = "../metrics.rs"]
mod metrics;
#[path = "../paths.rs"]
mod paths;
#[path = "../shm.rs"]
mod shm;

use std::path::{Path, PathBuf};
use std::time::{Duration, Instant};

use metrics::Snapshot;

const USAGE: &str = "\
qdbstat — live stats for the quanta_db extension

USAGE:
    qdbstat [OPTIONS]

OPTIONS:
    -r, --root <PATH>      Data root (default: $QUANTA_DB_ROOT). Used to locate
                           the derived metrics.shm + data segments.
        --shm <PATH>       Override the metrics arena path.
        --data-dir <PATH>  Override the data-segment directory.
    -n, --interval <SECS>  Refresh interval for live mode (default: 1).
    -1, --once             Print one snapshot and exit.
    -j, --json             Print one JSON snapshot and exit.
        --no-color         Disable ANSI color (also auto-off when piped or
                           NO_COLOR is set).
    -h, --help             Show this help.

Notes:
    The arena is per-pod under the pod's tmp dir; run this inside the pod you
    want to inspect (e.g. `kubectl exec <pod> -- qdbstat --once`).";

struct Args {
    root: Option<PathBuf>,
    shm: Option<PathBuf>,
    data_dir: Option<PathBuf>,
    interval: f64,
    once: bool,
    json: bool,
    no_color: bool,
}

fn parse_args() -> Result<Args, String> {
    let mut a = Args {
        root: std::env::var_os("QUANTA_DB_ROOT").map(PathBuf::from),
        shm: std::env::var_os("QUANTA_DB_METRICS_PATH").map(PathBuf::from),
        data_dir: std::env::var_os("QUANTA_DB_SHM_DIR").map(PathBuf::from),
        interval: 1.0,
        once: false,
        json: false,
        no_color: false,
    };
    let mut it = std::env::args().skip(1);
    while let Some(arg) = it.next() {
        let mut next = |flag: &str| it.next().ok_or_else(|| format!("{flag} needs a value"));
        match arg.as_str() {
            "-r" | "--root" => a.root = Some(PathBuf::from(next("--root")?)),
            "--shm" => a.shm = Some(PathBuf::from(next("--shm")?)),
            "--data-dir" => a.data_dir = Some(PathBuf::from(next("--data-dir")?)),
            "-n" | "--interval" => {
                a.interval = next("--interval")?
                    .parse()
                    .map_err(|_| "invalid --interval".to_string())?;
                if a.interval <= 0.0 {
                    return Err("--interval must be > 0".into());
                }
            }
            "-1" | "--once" => a.once = true,
            "-j" | "--json" => a.json = true,
            "--no-color" => a.no_color = true,
            "-h" | "--help" => {
                println!("{USAGE}");
                std::process::exit(0);
            }
            other => return Err(format!("unknown argument: {other}")),
        }
    }
    Ok(a)
}

/// Resolve the metrics.shm path and the data-segment dir from explicit
/// overrides or by deriving the per-root base (identical to `config`).
fn resolve_paths(a: &Args) -> Result<(PathBuf, PathBuf), String> {
    if let (Some(shm), Some(data)) = (&a.shm, &a.data_dir) {
        return Ok((shm.clone(), data.clone()));
    }
    let base = match &a.root {
        Some(root) => paths::derive_base(root)
            .map_err(|e| format!("cannot resolve root {}: {e}", root.display()))?,
        None => {
            return Err(
                "no data root: pass --root <PATH> or set QUANTA_DB_ROOT (or give --shm + --data-dir)"
                    .into(),
            )
        }
    };
    let shm = a.shm.clone().unwrap_or_else(|| paths::metrics_path(&base));
    let data = a.data_dir.clone().unwrap_or_else(|| paths::shm_dir(&base));
    Ok((shm, data))
}

#[derive(Default, Clone, Copy)]
struct SegStats {
    ok: bool,
    epoch: u64,
    nodes: u64,
    links: u64,
    doc_bytes: u64,
    img_bytes: u64,
    arena_used: u64,
    dead_bytes: u64,
    seg_size: u64,
    tombstones: u64,
}

fn seg_stats(dir: &Path, epoch: u64) -> SegStats {
    use std::sync::atomic::Ordering::Relaxed;
    if epoch == 0 {
        return SegStats::default();
    }
    match shm::SegmentReader::open(dir, epoch, 0) {
        Ok(r) => {
            let h = r.header();
            SegStats {
                ok: true,
                epoch,
                nodes: h.node_count.load(Relaxed),
                links: h.link_count.load(Relaxed),
                doc_bytes: h.doc_bytes.load(Relaxed),
                img_bytes: h.img_bytes.load(Relaxed),
                arena_used: h.arena_next.load(Relaxed),
                dead_bytes: h.dead_bytes.load(Relaxed),
                seg_size: h.seg_size,
                tombstones: h.tombstone_count.load(Relaxed),
            }
        }
        Err(_) => SegStats::default(),
    }
}

/// Open the arena read-only. `None` means metrics are off or not yet created.
fn open_arena(path: &Path) -> Option<&'static metrics::Metrics> {
    let p = unsafe { metrics::map_file(path, false, false).ok()? };
    let m = unsafe { &*p };
    if metrics::is_ready(m) {
        Some(m)
    } else {
        None
    }
}

fn snapshot(arena: Option<&metrics::Metrics>) -> Snapshot {
    arena.map(metrics::Metrics::snapshot).unwrap_or_default()
}

// --- formatting helpers ----------------------------------------------------

fn human_bytes(n: u64) -> String {
    const U: [&str; 6] = ["B", "KB", "MB", "GB", "TB", "PB"];
    let mut v = n as f64;
    let mut i = 0;
    while v >= 1024.0 && i < U.len() - 1 {
        v /= 1024.0;
        i += 1;
    }
    if i == 0 {
        format!("{n} B")
    } else {
        format!("{v:.1} {}", U[i])
    }
}

fn avg_ms(ns: u64, count: u64) -> String {
    if count == 0 {
        return "-".into();
    }
    format!("{:.3} ms", ns as f64 / count as f64 / 1e6)
}

fn ms(ns: u64) -> String {
    format!("{:.3} ms", ns as f64 / 1e6)
}

fn ratio_pct(hit: u64, miss: u64) -> String {
    let total = hit + miss;
    if total == 0 {
        return "-".into();
    }
    format!("{:.1}%", 100.0 * hit as f64 / total as f64)
}

fn per_sec(delta: u64, secs: f64) -> String {
    if secs <= 0.0 {
        return "-".into();
    }
    format!("{:.1}/s", delta as f64 / secs)
}

/// Group digits with thousands separators (12245 -> "12,245").
fn thousands(n: u64) -> String {
    let s = n.to_string();
    let b = s.as_bytes();
    let mut out = String::with_capacity(s.len() + s.len() / 3);
    for (i, ch) in b.iter().enumerate() {
        if i > 0 && (b.len() - i) % 3 == 0 {
            out.push(',');
        }
        out.push(*ch as char);
    }
    out
}

/// Unicode meter, `filled`/`total` cells for `frac` in [0,1].
fn bar(frac: f64, width: usize) -> String {
    let frac = if frac.is_finite() { frac.clamp(0.0, 1.0) } else { 0.0 };
    let filled = ((frac * width as f64).round() as usize).min(width);
    format!("{}{}", "█".repeat(filled), "░".repeat(width - filled))
}

// --- color -----------------------------------------------------------------

const C_RESET: &str = "\x1b[0m";
const C_GREEN: &str = "32";
const C_YELLOW: &str = "33";
const C_RED: &str = "31";
const C_DIM: &str = "2";
const C_BOLD: &str = "1";
const C_CYAN: &str = "36";

/// stdout is a terminal? (libc is already a crate dep via metrics.rs.)
fn stdout_is_tty() -> bool {
    unsafe { libc::isatty(1) == 1 }
}

/// Color on unless disabled by flag, `NO_COLOR`, or a non-tty stdout.
fn color_enabled(no_color_flag: bool) -> bool {
    !no_color_flag && std::env::var_os("NO_COLOR").is_none() && stdout_is_tty()
}

/// Wrap `s` in an SGR code when color is on; otherwise return it unchanged.
fn paint(on: bool, code: &str, s: &str) -> String {
    if on {
        format!("\x1b[{code}m{s}{C_RESET}")
    } else {
        s.to_string()
    }
}

/// A status dot colored by severity (green/yellow/red), or "*" without color.
fn dot(on: bool, code: &str) -> String {
    paint(on, code, "●")
}

// --- health verdict ---------------------------------------------------------

#[derive(Clone, Copy, PartialEq)]
enum Health {
    Healthy,
    Degraded,
    Fallback,
    Unknown, // metrics arena not found
}

impl Health {
    fn label(self) -> &'static str {
        match self {
            Health::Healthy => "HEALTHY",
            Health::Degraded => "DEGRADED",
            Health::Fallback => "FALLBACK",
            Health::Unknown => "NO METRICS",
        }
    }
    fn code(self) -> &'static str {
        match self {
            Health::Healthy => C_GREEN,
            Health::Degraded => C_YELLOW,
            Health::Fallback | Health::Unknown => C_RED,
        }
    }
}

/// Seconds since the daemon's last heartbeat, and whether the index is coherent.
fn coherence(s: &Snapshot) -> (u64, bool) {
    let now = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0);
    let hb_age = now.saturating_sub(s.watch_heartbeat_unix);
    let coherent = s.watch_coherent == 1 && s.watch_heartbeat_unix > 0 && hb_age <= 5;
    (hb_age, coherent)
}

fn verdict(s: &Snapshot, seg: &SegStats, arena_ok: bool) -> Health {
    if !arena_ok {
        return Health::Unknown;
    }
    let (hb_age, coherent) = coherence(s);
    if !coherent {
        return Health::Fallback;
    }
    let seg_frac = if seg.seg_size > 0 {
        seg.arena_used as f64 / seg.seg_size as f64
    } else {
        0.0
    };
    // Fallback reads are counted as a SHARE, not as a total. Every pod serves a
    // few from the per-process walk in the seconds between php-fpm accepting
    // traffic and qdbd publishing its first segment, and these counters are
    // cumulative for the life of the pod — so `> 0` pinned a perfectly healthy
    // pod to DEGRADED forever, which is exactly the false alarm that teaches
    // people to ignore the dashboard. An episode that is actually happening
    // now climbs this share quickly; `!coherent` above already catches a
    // daemon that is down.
    let reads_total = s.index_serves + s.file_reads + s.fallback_reads;
    let fallback_frac = if reads_total > 0 {
        s.fallback_reads as f64 / reads_total as f64
    } else {
        0.0
    };
    let warn = s.io_errors > 0
        || s.corrupt_json > 0
        || s.lock_timeouts > 0
        || s.shm_invalid > 0
        || s.uds_failures > 0
        || fallback_frac > 0.01
        || seg_frac > 0.85
        || hb_age >= 3;
    if warn {
        Health::Degraded
    } else {
        Health::Healthy
    }
}

// --- render ----------------------------------------------------------------

/// A section header rule: `─ TITLE ───────…` padded to `WIDTH`.
fn section(o: &mut String, col: bool, title: &str) {
    const WIDTH: usize = 44;
    let head = format!("─ {title} ");
    let pad = WIDTH.saturating_sub(head.chars().count());
    let line = format!("{head}{}", "─".repeat(pad));
    o.push_str(&paint(col, C_DIM, &line));
    o.push('\n');
}

/// A label/value row; the label is padded (uncolored) so columns line up even
/// when the value carries color escapes.
fn row(o: &mut String, label: &str, value: &str) {
    o.push_str(&format!("  {label:<15}{value}\n"));
}

fn render(
    s: &Snapshot,
    seg: &SegStats,
    prev: Option<(&Snapshot, f64)>,
    arena_ok: bool,
    col: bool,
) -> String {
    let mut o = String::new();
    let uptime = if s.started_unix > 0 {
        let now = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .map(|d| d.as_secs())
            .unwrap_or(s.started_unix);
        now.saturating_sub(s.started_unix)
    } else {
        0
    };
    let (hb_age, coherent) = coherence(s);
    let health = verdict(s, seg, arena_ok);

    // --- Banner: health verdict + uptime + coherence word --------------------
    let coh_word = if !arena_ok {
        paint(col, C_RED, "no metrics")
    } else if coherent {
        paint(col, C_GREEN, "coherent")
    } else if s.watch_heartbeat_unix > 0 {
        paint(col, C_RED, "stale — fallback")
    } else {
        paint(col, C_RED, "no daemon — fallback")
    };
    o.push_str(&format!(
        "{}  {} {}   up {}h{:02}m   {}\n",
        paint(col, C_BOLD, "QUANTA_DB"),
        dot(col, health.code()),
        paint(col, health.code(), health.label()),
        uptime / 3600,
        (uptime % 3600) / 60,
        coh_word,
    ));

    // --- Live activity line (only with a previous sample) --------------------
    // `lookups` is every name->path resolution (index hit, authoritative miss,
    // walk, negcache). It is the signal that moves on read-only page traffic
    // when the host wires only path resolution through the ext (doc reads and
    // list ops may stay 0), so it leads the line.
    if let Some((p, secs)) = prev {
        let queries = |x: &Snapshot| x.children_ops + x.find_ops + x.count_ops + x.links_ops;
        let lookups = |x: &Snapshot| {
            x.shm_hits + x.authoritative_misses + x.node_misses + x.fs_heals + x.neg_hits
        };
        let rate = |now: u64, was: u64| paint(col, C_CYAN, &per_sec(now.saturating_sub(was), secs));
        o.push_str(&format!(
            "  {} lookups {}  reads {}  writes {}  queries {}  events {}\n",
            paint(col, C_DIM, "now"),
            rate(lookups(s), lookups(p)),
            rate(s.reads, p.reads),
            rate(s.writes, p.writes),
            rate(queries(s), queries(p)),
            rate(s.watch_events, p.watch_events),
        ));
    }
    o.push('\n');

    // --- DAEMON --------------------------------------------------------------
    section(&mut o, col, "DAEMON");
    if arena_ok {
        let coh_dot = dot(col, if coherent { C_GREEN } else { C_RED });
        row(
            &mut o,
            "coherent",
            &format!(
                "{} {}   pid {}   data epoch {}",
                coh_dot,
                if coherent { "yes" } else { "no" },
                s.daemon_pid,
                s.data_epoch
            ),
        );
        // Resync cadence: ~1/min is the benign 60s periodic reconcile.
        let per_min = if uptime > 0 {
            s.watch_resyncs as f64 / (uptime as f64 / 60.0)
        } else {
            0.0
        };
        row(
            &mut o,
            "heartbeat",
            &format!(
                "{}s ago   resync {} (~{:.1}/min)   events {}",
                hb_age,
                s.watch_resyncs,
                per_min,
                thousands(s.watch_events)
            ),
        );
    } else {
        row(&mut o, "coherent", &paint(col, C_RED, "metrics arena not found — run inside the pod"));
    }

    // --- STORAGE -------------------------------------------------------------
    section(&mut o, col, "STORAGE");
    if seg.ok {
        let avg_doc = if seg.nodes > 0 { seg.doc_bytes / seg.nodes } else { 0 };
        row(
            &mut o,
            "nodes",
            &format!(
                "{}   links {}   docs {} (~{}/node)",
                thousands(seg.nodes),
                seg.links,
                human_bytes(seg.doc_bytes),
                human_bytes(avg_doc),
            ),
        );
        // Pre-decoded images: what lets a read skip json_decode entirely.
        // Zero here with a healthy daemon means every read is paying a parse.
        row(
            &mut o,
            "images",
            &if seg.img_bytes > 0 {
                format!(
                    "{}  ({:.0}% of doc bytes)",
                    human_bytes(seg.img_bytes),
                    if seg.doc_bytes > 0 {
                        seg.img_bytes as f64 * 100.0 / seg.doc_bytes as f64
                    } else {
                        0.0
                    },
                )
            } else {
                paint(col, C_YELLOW, "none — reads fall back to parsing raw JSON").to_string()
            },
        );
        let frac = if seg.seg_size > 0 {
            seg.arena_used as f64 / seg.seg_size as f64
        } else {
            0.0
        };
        let dead_pct = if seg.arena_used > 0 {
            100.0 * seg.dead_bytes as f64 / seg.arena_used as f64
        } else {
            0.0
        };
        let seg_code = if frac > 0.85 { C_YELLOW } else { C_GREEN };
        row(
            &mut o,
            "segment",
            &format!(
                "[{}] {:.0}%  {} / {}  dead {:.0}%",
                paint(col, seg_code, &bar(frac, 16)),
                frac * 100.0,
                human_bytes(seg.arena_used),
                human_bytes(seg.seg_size),
                dead_pct,
            ),
        );
    } else {
        row(&mut o, "segment", &paint(col, C_YELLOW, "none (fallback mode)"));
    }

    // --- READS (document content) --------------------------------------------
    section(&mut o, col, "READS");
    let served = s.index_serves + s.file_reads + s.fallback_reads;
    let idx_frac = if served > 0 { s.index_serves as f64 / served as f64 } else { 0.0 };
    let idx_code = if s.fallback_reads > 0 || s.file_reads > s.index_serves {
        C_YELLOW
    } else {
        C_GREEN
    };
    row(
        &mut o,
        "served",
        &format!(
            "[{}] {:.0}% shm   file {}   fallback {}",
            paint(col, idx_code, &bar(idx_frac, 16)),
            idx_frac * 100.0,
            s.file_reads,
            paint(col, if s.fallback_reads > 0 { C_YELLOW } else { C_DIM }, &s.fallback_reads.to_string()),
        ),
    );
    // Of the reads served from shared memory, how many skipped JSON parsing
    // entirely by using the pre-decoded image.
    let img_total = s.img_serves + s.img_absent;
    let img_frac = if img_total > 0 { s.img_serves as f64 / img_total as f64 } else { 0.0 };
    row(
        &mut o,
        "no-parse",
        &format!(
            "[{}] {:.0}% from image   no-image {}   invalid {}{}",
            paint(col, if s.img_invalid > 0 { C_YELLOW } else { C_GREEN }, &bar(img_frac, 16)),
            img_frac * 100.0,
            s.img_absent,
            paint(col, if s.img_invalid > 0 { C_YELLOW } else { C_DIM }, &s.img_invalid.to_string()),
            if s.seg_pin_max > 0 {
                format!("   pin-cap {}", s.seg_pin_max)
            } else {
                String::new()
            },
        ),
    );
    row(
        &mut o,
        "latency",
        &format!(
            "{} total   avg {}   peak {}",
            thousands(s.reads),
            avg_ms(s.read_ns, s.reads),
            ms(s.read_ns_max),
        ),
    );
    row(&mut o, "bytes", &human_bytes(s.bytes_read));

    // --- LOOKUPS (name -> path resolution) -----------------------------------
    section(&mut o, col, "LOOKUPS");
    row(
        &mut o,
        "resolved",
        &format!(
            "shm {}   authoritative {}   remaps {}",
            thousands(s.shm_hits),
            thousands(s.authoritative_misses),
            s.shm_remaps,
        ),
    );
    let walk_code = if s.fs_heals > 0 { C_YELLOW } else { C_DIM };
    row(
        &mut o,
        "walks",
        &format!(
            "fs-heal {}   miss {}   negcache {}",
            paint(col, walk_code, &s.fs_heals.to_string()),
            s.node_misses,
            s.neg_hits,
        ),
    );

    // --- QUERIES (listing / relation ops) ------------------------------------
    section(&mut o, col, "QUERIES");
    row(
        &mut o,
        "list",
        &format!(
            "children {}   find {}   count {}   links {}",
            thousands(s.children_ops),
            thousands(s.find_ops),
            thousands(s.count_ops),
            thousands(s.links_ops),
        ),
    );
    row(
        &mut o,
        "relation",
        &format!("link {}   unlink {}", s.link_ops, s.unlink_ops),
    );

    // --- WRITES --------------------------------------------------------------
    section(&mut o, col, "WRITES");
    row(
        &mut o,
        "ops",
        &format!(
            "{} writes   {} deletes   {}",
            thousands(s.writes),
            s.deletes,
            human_bytes(s.bytes_written),
        ),
    );
    // The rest of the write surface. `raw` is a subset of `writes` (documents
    // stored byte-for-byte as the caller supplied them); moves and doc-deletes
    // are their own operations and are not counted as writes.
    row(
        &mut o,
        "shape",
        &format!(
            "raw {}   moves {}   doc-deletes {}",
            thousands(s.raw_writes),
            s.moves,
            s.doc_deletes,
        ),
    );
    row(
        &mut o,
        "latency",
        &format!("avg {}   peak {}", avg_ms(s.write_ns, s.writes), ms(s.write_ns_max)),
    );
    let lock_val = if s.lock_timeouts > 0 {
        paint(col, C_RED, &format!("timeout {}", s.lock_timeouts))
    } else {
        paint(col, C_GREEN, "✓ no contention")
    };
    row(
        &mut o,
        "lock",
        &format!(
            "wait {} avg over {} acquires   {}",
            avg_ms(s.lock_wait_ns, s.lock_acquires),
            thousands(s.lock_acquires),
            lock_val,
        ),
    );

    // --- HEALTH --------------------------------------------------------------
    section(&mut o, col, "HEALTH");
    let flag = |on: bool, n: u64, good: &str, bad: &str| -> String {
        if n == 0 {
            format!("{} {}", dot(col, C_GREEN), good)
        } else {
            format!("{} {}", dot(on, C_RED), bad)
        }
    };
    row(
        &mut o,
        "errors",
        &format!(
            "{}   {}",
            flag(true, s.io_errors, "io 0", &format!("io {}", s.io_errors)),
            flag(true, s.corrupt_json, "json 0", &format!("json {}", s.corrupt_json)),
        ),
    );
    let uds_bad = s.uds_failures > 0;
    row(
        &mut o,
        "daemon-notify",
        &format!(
            "{} notified   {}",
            thousands(s.uds_notifies),
            if uds_bad {
                paint(col, C_YELLOW, &format!("{} failed", s.uds_failures))
            } else {
                format!("{} failed", s.uds_failures)
            },
        ),
    );
    row(
        &mut o,
        "segment",
        &format!(
            "{}   {}",
            flag(true, s.shm_invalid, "invalid 0", &format!("invalid {}", s.shm_invalid)),
            flag(true, s.lock_timeouts, "lock-timeout 0", &format!("lock-timeout {}", s.lock_timeouts)),
        ),
    );
    o
}

fn to_json(s: &Snapshot, seg: &SegStats, arena_ok: bool) -> String {
    let avg = |ns: u64, c: u64| if c == 0 { 0.0 } else { ns as f64 / c as f64 / 1e6 };
    // Derived, additive fields (existing keys are unchanged for consumers).
    let health = verdict(s, seg, arena_ok).label().to_ascii_lowercase();
    let served = s.index_serves + s.file_reads + s.fallback_reads;
    let frac = |n: u64| if served > 0 { n as f64 / served as f64 } else { 0.0 };
    let uptime = {
        let now = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .map(|d| d.as_secs())
            .unwrap_or(s.started_unix);
        now.saturating_sub(s.started_unix)
    };
    let resyncs_per_min = if uptime > 0 {
        s.watch_resyncs as f64 / (uptime as f64 / 60.0)
    } else {
        0.0
    };
    let avg_doc_bytes = if seg.nodes > 0 { seg.doc_bytes / seg.nodes } else { 0 };
    let segment_used_pct = if seg.seg_size > 0 {
        100.0 * seg.arena_used as f64 / seg.seg_size as f64
    } else {
        0.0
    };
    let dead_pct = if seg.arena_used > 0 {
        100.0 * seg.dead_bytes as f64 / seg.arena_used as f64
    } else {
        0.0
    };
    let json = serde_json::json!({
        "arena_ok": arena_ok,
        "started_unix": s.started_unix,
        "health": health,
        "read_mix": {
            "index": frac(s.index_serves),
            "file": frac(s.file_reads),
            "fallback": frac(s.fallback_reads),
        },
        "queries": {
            "children": s.children_ops,
            "find": s.find_ops,
            "count": s.count_ops,
            "links": s.links_ops,
            "link": s.link_ops,
            "unlink": s.unlink_ops,
        },
        "read_ns_max": s.read_ns_max,
        "write_ns_max": s.write_ns_max,
        "resyncs_per_min": resyncs_per_min,
        "avg_doc_bytes": avg_doc_bytes,
        "segment_used_pct": segment_used_pct,
        "dead_pct": dead_pct,
        "segment": {
            "ok": seg.ok,
            "epoch": seg.epoch,
            "nodes": seg.nodes,
            "links": seg.links,
            "doc_bytes": seg.doc_bytes,
            "img_bytes": seg.img_bytes,
            "arena_used": seg.arena_used,
            "dead_bytes": seg.dead_bytes,
            "seg_size": seg.seg_size,
            "tombstones": seg.tombstones,
        },
        "reads": s.reads,
        "read_ns": s.read_ns,
        "avg_read_ms": avg(s.read_ns, s.reads),
        "cache_hits": s.cache_hits,
        "cache_misses": s.cache_misses,
        "index_serves": s.index_serves,
        "file_reads": s.file_reads,
        "fallback_reads": s.fallback_reads,
        "bytes_read": s.bytes_read,
        "fs_heals": s.fs_heals,
        "node_misses": s.node_misses,
        "neg_hits": s.neg_hits,
        "authoritative_misses": s.authoritative_misses,
        "shm_hits": s.shm_hits,
        "shm_remaps": s.shm_remaps,
        "shm_invalid": s.shm_invalid,
        "data_epoch": s.data_epoch,
        "daemon_pid": s.daemon_pid,
        "uds_notifies": s.uds_notifies,
        "uds_failures": s.uds_failures,
        "watch_coherent": s.watch_coherent,
        "watch_heartbeat_unix": s.watch_heartbeat_unix,
        "watch_epoch": s.watch_epoch,
        "watch_events": s.watch_events,
        "watch_resyncs": s.watch_resyncs,
        "writes": s.writes,
        "write_ns": s.write_ns,
        "avg_write_ms": avg(s.write_ns, s.writes),
        "bytes_written": s.bytes_written,
        "deletes": s.deletes,
        "lock_acquires": s.lock_acquires,
        "lock_wait_ns": s.lock_wait_ns,
        "lock_timeouts": s.lock_timeouts,
        "io_errors": s.io_errors,
        "corrupt_json": s.corrupt_json,
    });
    json.to_string()
}

fn main() {
    let args = match parse_args() {
        Ok(a) => a,
        Err(e) => {
            eprintln!("qdbstat: {e}\n\n{USAGE}");
            std::process::exit(2);
        }
    };
    let (shm_path, data_dir) = match resolve_paths(&args) {
        Ok(p) => p,
        Err(e) => {
            eprintln!("qdbstat: {e}");
            std::process::exit(2);
        }
    };

    let arena = open_arena(&shm_path);
    let arena_ok = arena.is_some();
    let col = color_enabled(args.no_color);

    if args.json {
        let s = snapshot(arena);
        let seg = seg_stats(&data_dir, s.data_epoch);
        println!("{}", to_json(&s, &seg, arena_ok));
        return;
    }

    if args.once {
        let s = snapshot(arena);
        let seg = seg_stats(&data_dir, s.data_epoch);
        print!("{}", render(&s, &seg, None, arena_ok, col));
        return;
    }

    // Live mode: redraw each interval with per-interval deltas.
    let interval = Duration::from_secs_f64(args.interval);
    let mut prev: Option<(Snapshot, Instant)> = None;
    loop {
        let now = Instant::now();
        let s = snapshot(arena);
        let seg = seg_stats(&data_dir, s.data_epoch);
        let prev_ref = prev
            .as_ref()
            .map(|(ps, pt)| (ps, now.duration_since(*pt).as_secs_f64()));
        print!("\x1b[2J\x1b[H{}", render(&s, &seg, prev_ref, arena_ok, col));
        use std::io::Write;
        let _ = std::io::stdout().flush();
        prev = Some((s, now));
        std::thread::sleep(interval);
    }
}
