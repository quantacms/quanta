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
// shm.rs builds the segment-wide string table out of ready-made `zend_string`s
// (v3), so it needs php_abi. That module is PHP-free by design — it only
// encodes the layout — so pulling it in here costs qdbstat nothing.
#[path = "../php_abi.rs"]
mod php_abi;
#[path = "../shm.rs"]
mod shm;
// The dashboard renders imaged documents back into JSON, which is what `image`
// does for the daemon-free callers already (`Image::to_value`). PHP-free, like
// everything else included here.
#[path = "../image.rs"]
mod image;
#[path = "../dashboard.rs"]
mod dashboard;

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
    -p, --prometheus       Print one Prometheus text exposition and exit.
        --listen [ADDR]    Serve /metrics over HTTP until killed
                           (default: 0.0.0.0:9109, or $QUANTA_DB_METRICS_LISTEN).
        --dashboard [ADDR] Serve the web browser for the indexed files until
                           killed (default: 127.0.0.1:9110, or
                           $QUANTA_DB_DASHBOARD_LISTEN). Unlike --listen this
                           serves node paths and document content, so it binds
                           loopback unless told otherwise and honours
                           $QUANTA_DB_DASHBOARD_TOKEN as a shared secret.
        --base-path <PATH> URL prefix the dashboard is reached at, e.g. /qdb
                           (default: none, or $QUANTA_DB_DASHBOARD_BASE_PATH).
                           Set it when an ingress routes a sub-path here: the
                           request arrives with the prefix still on it.
        --exit-code        Exit by verdict instead of always 0:
                           0 healthy, 1 degraded, 2 fallback, 3 no metrics.
        --no-color         Disable ANSI color (also auto-off when piped or
                           NO_COLOR is set).
    -h, --help             Show this help.

Notes:
    The arena is per-pod under the pod's tmp dir; run this inside the pod you
    want to inspect (e.g. `kubectl exec <pod> -- qdbstat --once`). For the same
    reason --listen belongs in the pod being measured, not beside it: the arena
    is in the web container's own filesystem, so a sidecar cannot see it.";

struct Args {
    root: Option<PathBuf>,
    shm: Option<PathBuf>,
    data_dir: Option<PathBuf>,
    interval: f64,
    once: bool,
    json: bool,
    prometheus: bool,
    listen: Option<String>,
    dashboard: Option<String>,
    base_path: String,
    exit_code: bool,
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
        prometheus: false,
        listen: None,
        dashboard: None,
        base_path: std::env::var("QUANTA_DB_DASHBOARD_BASE_PATH").unwrap_or_default(),
        exit_code: false,
        no_color: false,
    };
    // Indexed rather than an iterator so `--listen` can look at what follows
    // without consuming it: its address is optional, and `--listen --exit-code`
    // must not swallow the next flag as an address.
    let argv: Vec<String> = std::env::args().skip(1).collect();
    let mut i = 0;
    while i < argv.len() {
        let arg = argv[i].as_str();
        let next = |flag: &str, i: &mut usize| {
            *i += 1;
            argv.get(*i).cloned().ok_or_else(|| format!("{flag} needs a value"))
        };
        match arg {
            "-r" | "--root" => a.root = Some(PathBuf::from(next("--root", &mut i)?)),
            "--shm" => a.shm = Some(PathBuf::from(next("--shm", &mut i)?)),
            "--data-dir" => a.data_dir = Some(PathBuf::from(next("--data-dir", &mut i)?)),
            "-n" | "--interval" => {
                a.interval = next("--interval", &mut i)?
                    .parse()
                    .map_err(|_| "invalid --interval".to_string())?;
                if a.interval <= 0.0 {
                    return Err("--interval must be > 0".into());
                }
            }
            "-1" | "--once" => a.once = true,
            "-j" | "--json" => a.json = true,
            "-p" | "--prometheus" => a.prometheus = true,
            "--listen" => {
                let addr = match argv.get(i + 1) {
                    Some(v) if !v.starts_with('-') => {
                        i += 1;
                        v.clone()
                    }
                    // The env var supplies the address, never the decision to
                    // serve: `qdbstat` with no flags must stay the interactive
                    // dashboard even inside a pod that exports metrics, which
                    // is exactly where someone exec's in to look at it.
                    _ => std::env::var("QUANTA_DB_METRICS_LISTEN")
                        .ok()
                        .filter(|v| !v.is_empty())
                        .unwrap_or_else(|| DEFAULT_LISTEN.to_string()),
                };
                a.listen = Some(addr);
            }
            // Same optional-value shape as --listen, and for the same reason:
            // `--dashboard --no-color` must not read the flag as an address.
            "--dashboard" => {
                let addr = match argv.get(i + 1) {
                    Some(v) if !v.starts_with('-') => {
                        i += 1;
                        v.clone()
                    }
                    _ => std::env::var("QUANTA_DB_DASHBOARD_LISTEN")
                        .ok()
                        .filter(|v| !v.is_empty())
                        .unwrap_or_else(|| dashboard::DEFAULT_LISTEN.to_string()),
                };
                a.dashboard = Some(addr);
            }
            "--base-path" => a.base_path = next("--base-path", &mut i)?,
            "--exit-code" => a.exit_code = true,
            "--no-color" => a.no_color = true,
            "-h" | "--help" => {
                println!("{USAGE}");
                std::process::exit(0);
            }
            other => return Err(format!("unknown argument: {other}")),
        }
        i += 1;
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
    str_bytes: u64,
    str_count: u64,
    raw_bytes: u64,
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
                str_bytes: h.str_bytes.load(Relaxed),
                str_count: h.str_count.load(Relaxed),
                raw_bytes: h.raw_bytes.load(Relaxed),
            }
        }
        Err(_) => SegStats::default(),
    }
}

/// Capacity of the filesystem the data segments live on — `/dev/shm` in the
/// container.
///
/// Nothing in the arena or the segment header knows this: `seg_size` is how
/// much a segment *mapped*, not how much tmpfs there is to back it. The
/// distinction is the whole failure mode. tmpfs lets a sparse file exceed the
/// mount, so an oversized segment is created happily and dies later, on a write
/// fault, with SIGBUS — which is how a compaction that could not fit presented
/// itself as a crash loop rather than an allocation error. A compaction holds
/// the old segment and the new one at once, so the question worth answering is
/// "would two of these fit", and it cannot be answered without the denominator.
#[derive(Default, Clone, Copy)]
struct ShmFs {
    ok: bool,
    total: u64,
    avail: u64,
    /// `total - avail`: pages actually resident, which is what the kernel
    /// charges. Well below the sum of the segment sizes when they are sparse.
    used: u64,
}

fn shm_fs(dir: &Path) -> ShmFs {
    use std::os::unix::ffi::OsStrExt;
    let Ok(path) = std::ffi::CString::new(dir.as_os_str().as_bytes()) else {
        return ShmFs::default();
    };
    let mut st: libc::statvfs = unsafe { std::mem::zeroed() };
    if unsafe { libc::statvfs(path.as_ptr(), &mut st) } != 0 {
        return ShmFs::default();
    }
    // f_frsize is the fragment size block counts are expressed in; f_bsize is
    // only the preferred I/O size and is not always the same number.
    let unit = if st.f_frsize > 0 { st.f_frsize as u64 } else { st.f_bsize as u64 };
    let total = (st.f_blocks as u64).saturating_mul(unit);
    // f_bavail, not f_bfree: the unprivileged figure, which is what a segment
    // written by www-data can actually claim.
    let avail = (st.f_bavail as u64).saturating_mul(unit);
    ShmFs { ok: total > 0, total, avail, used: total.saturating_sub(avail) }
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

/// Compact age for a past event: `45s`, `12m`, `3h`, `2d`.
fn short_age(secs: u64) -> String {
    match secs {
        0..=99 => format!("{secs}s"),
        100..=5_999 => format!("{}m", secs / 60),
        6_000..=172_799 => format!("{}h", secs / 3_600),
        _ => format!("{}d", secs / 86_400),
    }
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
    /// The machine-readable spelling, for the `--prometheus` state label. Kept
    /// apart from `label()`, which is a human banner and says "NO METRICS"
    /// where a label value has to be a stable identifier.
    fn state(self) -> &'static str {
        match self {
            Health::Healthy => "healthy",
            Health::Degraded => "degraded",
            Health::Fallback => "fallback",
            Health::Unknown => "unknown",
        }
    }
}

/// How long a failed daemon notify keeps the pod DEGRADED. Long enough that a
/// daemon flapping on the write path never clears between episodes, short
/// enough that one healed blip does not outlive the incident it belongs to.
const UDS_WARN_SECS: u64 = 60;

/// Unix seconds now, or 0 if the clock predates the epoch.
fn now_unix() -> u64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0)
}

/// Whether a notify failure is recent enough to still count against health.
///
/// `uds_failures` is cumulative for the life of the pod, so `> 0` graded a
/// single startup-window blip as DEGRADED forever — the same false alarm the
/// share treatment fixed for `fallback_reads`. A share does not work here: the
/// denominator is notify *attempts*, and a pod that serves 100k reads may make
/// only a handful of writes, so one failure against 8 notifies reads as 11%
/// and never decays. Recency is what "is this happening now" means for an
/// event this rare. Nothing is lost by letting it age out: every failure
/// poisons coherence in `notify_daemon`, so a live episode is already the
/// stronger FALLBACK verdict above, and its aftermath shows in `fallback_frac`.
fn uds_recent(s: &Snapshot) -> bool {
    s.uds_failure_unix > 0 && now_unix().saturating_sub(s.uds_failure_unix) < UDS_WARN_SECS
}

/// Seconds since the daemon's last heartbeat, and whether the index is coherent.
fn coherence(s: &Snapshot) -> (u64, bool) {
    let hb_age = now_unix().saturating_sub(s.watch_heartbeat_unix);
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
        || uds_recent(s)
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
    fs: &ShmFs,
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
            x.shm_hits
                + x.authoritative_misses
                + x.node_misses
                + x.fs_heals
                + x.neg_hits
                + x.stale_hits
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
        // The segment-wide string table (layout v3). Its value is the ratio:
        // one entry serves every document that uses the string, where the
        // per-document tables it replaced re-emitted a `zend_string` for every
        // repeated key in every node. A count close to the node count means
        // the tree's documents share almost nothing and the table is not
        // earning its keep; far below it is the expected, healthy shape.
        if seg.str_count > 0 {
            row(
                &mut o,
                "strings",
                &format!(
                    "{}  shared ({} entries, ~{}/entry)",
                    human_bytes(seg.str_bytes),
                    thousands(seg.str_count),
                    human_bytes(seg.str_bytes / seg.str_count.max(1)),
                ),
            );
        }
        // Raw JSON resident in the segment. A document stores its image OR its
        // bytes, never both, so anything here is a document that could not be
        // imaged — and every read of one costs a parse instead of a walk.
        // Non-zero is not an error; a LARGE number means the image cap
        // (image_max_doc_kb) is turning documents away.
        row(
            &mut o,
            "raw json",
            &if seg.raw_bytes == 0 {
                "none resident — every document is served from its image".to_string()
            } else {
                paint(
                    col,
                    C_YELLOW,
                    &format!(
                        "{} for un-imaged documents (they cost a parse per read)",
                        human_bytes(seg.raw_bytes)
                    ),
                )
                .to_string()
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
    // Headroom, not occupancy. A compaction maps the new segment while the old
    // one is still mapped, so the number that decides whether the next one
    // survives is two segments against the whole tmpfs -- and a tmpfs lets the
    // oversized mapping succeed and kills the writer later with SIGBUS, so
    // nothing upstream of this row reports the problem until it is a crash.
    if fs.ok {
        let need = seg.seg_size.saturating_mul(2);
        let frac = need as f64 / fs.total as f64;
        let code = if frac > 0.8 {
            C_RED
        } else if frac > 0.5 {
            C_YELLOW
        } else {
            C_GREEN
        };
        row(
            &mut o,
            "/dev/shm",
            &format!(
                "{} / {} used   next compaction needs {} ({})",
                human_bytes(fs.used),
                human_bytes(fs.total),
                human_bytes(need),
                paint(col, code, &format!("{:.0}% of the mount", frac * 100.0)),
            ),
        );
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
    // What those walks COST. A degraded worker's whole bill is here: the count
    // says how often the tree was re-read, the peak says how long one request
    // could block on it. Both stay zero while the index is serving.
    if s.snap_walks > 0 {
        let cost_code = if s.snap_walk_ns_max > 1_000_000_000 {
            C_YELLOW
        } else {
            C_DIM
        };
        row(
            &mut o,
            "walk cost",
            &format!(
                "{} walks   avg {}   peak {}",
                thousands(s.snap_walks),
                avg_ms(s.snap_walk_ns, s.snap_walks),
                paint(col, cost_code, &ms(s.snap_walk_ns_max)),
            ),
        );
    }
    // Lookups answered from a segment no daemon is maintaining any more. Each
    // one is a tree walk that did not happen, so a non-zero count is the fix
    // working, not a fault -- what to watch is the unconfirmed SHARE, which is
    // how far the segment has drifted from the tree beneath it.
    if s.stale_hits > 0 || s.stale_unconfirmed > 0 {
        let seen = s.stale_hits + s.stale_unconfirmed;
        let drift = s.stale_unconfirmed as f64 / seen as f64;
        let drift_code = if drift > 0.10 { C_YELLOW } else { C_DIM };
        row(
            &mut o,
            "stale index",
            &format!(
                "served {}   unconfirmed {}",
                thousands(s.stale_hits),
                paint(
                    col,
                    drift_code,
                    &format!("{} ({:.0}%)", thousands(s.stale_unconfirmed), drift * 100.0),
                ),
            ),
        );
    }

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
    // The age is the whole point of the row: a failure count with no "when"
    // cannot be told apart from one that healed an hour ago.
    let failed = if s.uds_failures > 0 && s.uds_failure_unix > 0 {
        format!(
            "{} failed ({} ago)",
            s.uds_failures,
            short_age(now_unix().saturating_sub(s.uds_failure_unix))
        )
    } else {
        format!("{} failed", s.uds_failures)
    };
    row(
        &mut o,
        "daemon-notify",
        &format!(
            "{} notified   {}",
            thousands(s.uds_notifies),
            if uds_recent(s) {
                paint(col, C_YELLOW, &failed)
            } else {
                failed
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

fn to_json(s: &Snapshot, seg: &SegStats, fs: &ShmFs, arena_ok: bool) -> String {
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
            "str_bytes": seg.str_bytes,
            "str_count": seg.str_count,
            "raw_bytes": seg.raw_bytes,
        },
        // The tmpfs the segments live on. Not derivable from anything above:
        // `seg_size` is what a segment mapped, not what there is to back it,
        // and a compaction needs two segments' worth at once.
        "shm": {
            "ok": fs.ok,
            "total_bytes": fs.total,
            "used_bytes": fs.used,
            "avail_bytes": fs.avail,
            "compaction_needs_bytes": seg.seg_size.saturating_mul(2),
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
        "stale_hits": s.stale_hits,
        "stale_unconfirmed": s.stale_unconfirmed,
        "snap_walks": s.snap_walks,
        "snap_walk_ns": s.snap_walk_ns,
        "snap_walk_ns_max": s.snap_walk_ns_max,
        "shm_hits": s.shm_hits,
        "shm_remaps": s.shm_remaps,
        "shm_invalid": s.shm_invalid,
        "data_epoch": s.data_epoch,
        "daemon_pid": s.daemon_pid,
        "uds_notifies": s.uds_notifies,
        "uds_failures": s.uds_failures,
        "uds_failure_unix": s.uds_failure_unix,
        "uds_recent": uds_recent(s),
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

// --- prometheus exposition -------------------------------------------------

/// Every metric name below is prefixed with this, per the convention that an
/// exporter namespaces its families by the thing it measures.
const PROM_PREFIX: &str = "quanta_db";

/// A text-exposition builder: one `# HELP` / `# TYPE` pair per family, then its
/// samples. Prometheus rejects a family whose samples are interleaved with
/// another's, so the writing order here *is* the grouping — a family is opened
/// once and all of its samples follow immediately.
struct Exposition {
    out: String,
}

impl Exposition {
    fn new() -> Self {
        Exposition { out: String::with_capacity(8 * 1024) }
    }

    /// Open a family. `help` is a single line; the exposition format has no
    /// escape for a newline in it that is worth the trouble.
    fn family(&mut self, name: &str, kind: &str, help: &str) {
        self.out.push_str(&format!(
            "# HELP {PROM_PREFIX}_{name} {help}\n# TYPE {PROM_PREFIX}_{name} {kind}\n"
        ));
    }

    /// A sample of the currently open family. Labels are emitted verbatim:
    /// everything this exporter labels is a fixed identifier from the code
    /// below, never a value read out of the arena, so there is nothing to
    /// escape and no cardinality to get away from us.
    fn sample(&mut self, name: &str, labels: &[(&str, &str)], value: &str) {
        self.out.push_str(&format!("{PROM_PREFIX}_{name}"));
        if !labels.is_empty() {
            let pairs: Vec<String> =
                labels.iter().map(|(k, v)| format!("{k}=\"{v}\"")).collect();
            self.out.push_str(&format!("{{{}}}", pairs.join(",")));
        }
        self.out.push_str(&format!(" {value}\n"));
    }

    /// Counter/gauge with no labels, integer valued.
    fn int(&mut self, name: &str, kind: &str, help: &str, value: u64) {
        self.family(name, kind, help);
        self.sample(name, &[], &value.to_string());
    }

    /// Counter/gauge with no labels, real valued. Six decimals is past the
    /// resolution of anything here (a nanosecond in a seconds-valued counter is
    /// 1e-9, but such a counter is already thousands of seconds wide) and keeps
    /// the output out of scientific notation, which older parsers dislike.
    fn real(&mut self, name: &str, kind: &str, help: &str, value: f64) {
        self.family(name, kind, help);
        self.sample(name, &[], &format!("{value:.6}"));
    }

    /// A labelled family written in one go.
    fn labelled(&mut self, name: &str, kind: &str, help: &str, samples: &[(&str, &str, u64)]) {
        self.family(name, kind, help);
        for (label, value, n) in samples {
            self.sample(name, &[(label, value)], &n.to_string());
        }
    }
}

/// Nanosecond counters are exported in seconds: Prometheus convention is base
/// units, and `rate(x_seconds_total[5m]) / rate(x_total[5m])` then reads
/// directly as average latency in seconds.
fn secs(ns: u64) -> f64 {
    ns as f64 / 1e9
}

/// Render the snapshot as a Prometheus text exposition.
///
/// Ratios that a query could compute are exported anyway where they are the
/// number someone actually watches (`segment_used_ratio`), but never *instead*
/// of their components — the components are counters, so a dashboard can rate
/// them over a window, which is the only honest way to ask "is this happening
/// now". The lifetime shares carry that caveat in their HELP text: these
/// counters are cumulative for the life of the pod, so a share computed from
/// them dilutes for as long as the pod runs and cannot tell a live episode from
/// an old one.
///
/// Families whose source is genuinely absent (no segment while in fallback, no
/// statvfs) are omitted rather than zero-filled: a gap in a graph is the truth,
/// and a zero denominator silently turns every derived ratio into a wrong
/// answer instead of no answer.
fn to_prometheus(s: &Snapshot, seg: &SegStats, fs: &ShmFs, arena_ok: bool) -> String {
    let mut e = Exposition::new();
    let health = verdict(s, seg, arena_ok);
    let (hb_age, coherent) = coherence(s);

    // --- daemon / verdict ---------------------------------------------------
    e.int(
        "up",
        "gauge",
        "1 if the metrics arena was readable; 0 if metrics are off or the pod has not created it yet.",
        arena_ok as u64,
    );
    e.family(
        "health",
        "gauge",
        "Composite qdbstat verdict, 1 on the active state. Soft signals feed it, so it is a dashboard signal rather than an alerting one.",
    );
    for state in ["healthy", "degraded", "fallback", "unknown"] {
        let on = state == health.state();
        e.sample("health", &[("state", state)], if on { "1" } else { "0" });
    }
    if !arena_ok {
        // Without the arena every counter below would be a fabricated zero.
        // `up 0` plus the verdict is the whole truth available here.
        return e.out;
    }
    e.int(
        "watch_coherent",
        "gauge",
        "1 while the daemon's view of the tree is authoritative; 0 means every read is on the fallback walk.",
        s.watch_coherent,
    );
    e.int(
        "heartbeat_timestamp_seconds",
        "gauge",
        "Unix time of the daemon's last heartbeat; 0 if it has never beaten.",
        s.watch_heartbeat_unix,
    );
    if s.watch_heartbeat_unix > 0 {
        e.int(
            "heartbeat_age_seconds",
            "gauge",
            "Seconds since the daemon's last heartbeat. Absent when it has never beaten.",
            hb_age,
        );
    }
    e.int("coherent", "gauge", "1 when the heartbeat is both present and fresh.", coherent as u64);
    e.int("daemon_pid", "gauge", "PID of the daemon that owns the current segment; 0 if none.", s.daemon_pid);
    e.int("data_epoch", "gauge", "Segment epoch the extension is reading.", s.data_epoch);
    e.int("watch_epoch", "gauge", "Segment epoch the daemon has published.", s.watch_epoch);
    e.int("uptime_seconds", "gauge", "Seconds since the metrics arena was created.", {
        let now = now_unix();
        if s.started_unix > 0 { now.saturating_sub(s.started_unix) } else { 0 }
    });
    e.int("watch_events_total", "counter", "inotify events the daemon has applied.", s.watch_events);
    e.int(
        "watch_resyncs_total",
        "counter",
        "Full reconcile walks. A steady ~1/min is the periodic safety net; bursts mean the watcher is missing events.",
        s.watch_resyncs,
    );
    e.labelled(
        "uds_notifies_total",
        "counter",
        "Write notifications sent to the daemon over the unix socket, by outcome.",
        &[("result", "ok", s.uds_notifies.saturating_sub(s.uds_failures)), ("result", "failed", s.uds_failures)],
    );
    e.int(
        "uds_failure_timestamp_seconds",
        "gauge",
        "Unix time of the last failed notify; 0 if none has ever failed.",
        s.uds_failure_unix,
    );

    // --- segment + the tmpfs backing it -------------------------------------
    // The pair the compaction-headroom question needs: a compaction holds two
    // segments at once, so it is `2 * segment_bytes` that has to fit in
    // `shm_total_bytes`, and neither number is derivable from the other.
    if seg.ok {
        e.int("segment_bytes", "gauge", "Size the active segment mapped.", seg.seg_size);
        e.int("segment_used_bytes", "gauge", "Bytes of the segment arena in use.", seg.arena_used);
        e.real(
            "segment_used_ratio",
            "gauge",
            "Segment arena in use as a fraction of its mapped size.",
            if seg.seg_size > 0 { seg.arena_used as f64 / seg.seg_size as f64 } else { 0.0 },
        );
        e.int("segment_dead_bytes", "gauge", "Bytes in the segment arena superseded but not yet reclaimed.", seg.dead_bytes);
        e.int("segment_nodes", "gauge", "Nodes resident in the segment.", seg.nodes);
        e.int("segment_links", "gauge", "Links resident in the segment.", seg.links);
        e.int("segment_tombstones", "gauge", "Deleted entries still occupying segment slots.", seg.tombstones);
        e.labelled(
            "segment_content_bytes",
            "gauge",
            "Segment bytes by kind. raw is JSON no image could be built for, and every read of one costs a parse.",
            &[
                ("kind", "doc", seg.doc_bytes),
                ("kind", "image", seg.img_bytes),
                ("kind", "raw", seg.raw_bytes),
                ("kind", "strings", seg.str_bytes),
            ],
        );
        e.int("segment_string_entries", "gauge", "Entries in the segment-wide string table.", seg.str_count);
    }
    if fs.ok {
        e.int(
            "shm_total_bytes",
            "gauge",
            "Capacity of the tmpfs the segments live on. The denominator of the compaction-headroom question.",
            fs.total,
        );
        e.int(
            "shm_used_bytes",
            "gauge",
            "Resident bytes on that tmpfs. Below the sum of segment sizes while segments are sparse.",
            fs.used,
        );
        e.int("shm_avail_bytes", "gauge", "Bytes still claimable on that tmpfs by an unprivileged writer.", fs.avail);
    }

    // --- reads --------------------------------------------------------------
    let served = s.index_serves + s.file_reads + s.fallback_reads;
    e.labelled(
        "served_total",
        "counter",
        "Document reads by what served them: the index, a single file read, or the fallback tree walk.",
        &[
            ("source", "index", s.index_serves),
            ("source", "file", s.file_reads),
            ("source", "fallback", s.fallback_reads),
        ],
    );
    e.real(
        "fallback_read_ratio",
        "gauge",
        "Fallback share of all reads over the life of the pod. Cumulative, so it dilutes with uptime -- rate() served_total for what is happening now.",
        if served > 0 { s.fallback_reads as f64 / served as f64 } else { 0.0 },
    );
    e.int("reads_total", "counter", "Document reads.", s.reads);
    e.real("read_seconds_total", "counter", "Time spent in document reads.", secs(s.read_ns));
    e.real("read_seconds_max", "gauge", "Slowest single document read since the pod started.", secs(s.read_ns_max));
    e.int("read_bytes_total", "counter", "Document bytes returned to callers.", s.bytes_read);
    e.labelled(
        "cache_total",
        "counter",
        "Per-process document cache outcomes.",
        &[("result", "hit", s.cache_hits), ("result", "miss", s.cache_misses)],
    );
    e.labelled(
        "image_total",
        "counter",
        "Pre-decoded document images by outcome. An absent or invalid image means that read paid a JSON parse.",
        &[
            ("result", "served", s.img_serves),
            ("result", "absent", s.img_absent),
            ("result", "invalid", s.img_invalid),
        ],
    );

    // --- lookups (name -> path resolution) ----------------------------------
    // The counter set that moves on read-only page traffic even when documents
    // are served from elsewhere, which makes it the earliest place a degraded
    // resolver shows up.
    e.labelled(
        "lookups_total",
        "counter",
        "Name-to-path resolutions by how they resolved. walk and heal are the expensive ones.",
        &[
            ("result", "index", s.shm_hits),
            ("result", "authoritative_miss", s.authoritative_misses),
            ("result", "walk", s.node_misses),
            ("result", "heal", s.fs_heals),
            ("result", "negative", s.neg_hits),
            ("result", "stale", s.stale_hits),
        ],
    );
    e.int(
        "stale_unconfirmed_total",
        "counter",
        "Stale-index hits never confirmed against the filesystem -- the drift a persisted index can carry.",
        s.stale_unconfirmed,
    );
    e.int("snapshot_walks_total", "counter", "Whole-tree walks taken to build a fallback snapshot.", s.snap_walks);
    e.real("snapshot_walk_seconds_total", "counter", "Time spent in those walks.", secs(s.snap_walk_ns));
    e.real("snapshot_walk_seconds_max", "gauge", "Slowest single fallback walk since the pod started.", secs(s.snap_walk_ns_max));
    // Deliberately not a {hit, remap, invalid} family: the "hit" of a segment
    // mapping is the same counter as `lookups_total{result="index"}`, and one
    // counter exported under two names is a trap for whoever later sums them.
    // `shm_invalid` is in `errors_total`, which is where someone looks for it.
    e.int(
        "segment_remaps_total",
        "counter",
        "Times a worker re-mapped onto a newly published segment epoch.",
        s.shm_remaps,
    );
    e.int("segment_pins", "gauge", "Segments currently pinned by in-flight reads.", s.seg_pins);
    e.int("segment_pins_max", "gauge", "High-water mark of pinned segments.", s.seg_pin_max);

    // --- queries ------------------------------------------------------------
    e.labelled(
        "queries_total",
        "counter",
        "Query operations by kind.",
        &[
            ("op", "children", s.children_ops),
            ("op", "find", s.find_ops),
            ("op", "count", s.count_ops),
            ("op", "links", s.links_ops),
            ("op", "link", s.link_ops),
            ("op", "unlink", s.unlink_ops),
        ],
    );

    // --- writes -------------------------------------------------------------
    e.int("writes_total", "counter", "Document writes.", s.writes);
    e.real("write_seconds_total", "counter", "Time spent in document writes.", secs(s.write_ns));
    e.real("write_seconds_max", "gauge", "Slowest single write since the pod started.", secs(s.write_ns_max));
    e.int("write_bytes_total", "counter", "Document bytes written.", s.bytes_written);
    e.labelled(
        "mutations_total",
        "counter",
        "Tree mutations by kind.",
        &[
            ("op", "delete", s.deletes),
            ("op", "doc_delete", s.doc_deletes),
            ("op", "move", s.moves),
            ("op", "raw_write", s.raw_writes),
        ],
    );

    // --- locks + errors -----------------------------------------------------
    e.int("lock_acquires_total", "counter", "Write-lock acquisitions.", s.lock_acquires);
    e.real("lock_wait_seconds_total", "counter", "Time spent waiting for the write lock.", secs(s.lock_wait_ns));
    e.int("lock_timeouts_total", "counter", "Write-lock acquisitions that timed out.", s.lock_timeouts);
    e.labelled(
        "errors_total",
        "counter",
        "Errors by kind. Any of these is worth a look; none of them is normal.",
        &[
            ("kind", "io", s.io_errors),
            ("kind", "corrupt_json", s.corrupt_json),
            ("kind", "shm_invalid", s.shm_invalid),
            ("kind", "lock_timeout", s.lock_timeouts),
        ],
    );

    e.out
}

// --- /metrics over HTTP ----------------------------------------------------

/// Default listen address for `--listen` with no value and for the
/// `QUANTA_DB_METRICS_LISTEN` opt-in.
///
/// All interfaces, because the point is to be scraped from outside the pod, and
/// port 9109 because the only things already listening in the image are nginx
/// on 80 and php-fpm on 9000. Nothing here is secret — counters, sizes and
/// epochs, no paths and no document content — but it is also not access
/// control, so a deployment that exposes pod IPs broadly should set its own
/// address.
const DEFAULT_LISTEN: &str = "0.0.0.0:9109";

/// The arena, re-opened from scratch on every scrape.
///
/// A one-shot `qdbstat` maps the arena and exits, so it cannot outlive it. The
/// exporter runs for the life of the pod, where three things can happen to the
/// file under a held mapping:
///
///   * it does not exist yet when the exporter starts — the arena is created by
///     whichever of qdbd or the first php-fpm worker gets there first;
///   * it is recreated, and the mapping then describes a file nobody writes to;
///   * **overlayfs copies it up.** This is the one that is invisible. If the
///     image ships a stale arena in a lower layer, the first writer's `O_RDWR`
///     open copies the file to the upper layer and writes there, while a reader
///     that opened the lower copy keeps its frozen view forever. overlayfs
///     deliberately keeps `st_ino` stable across a copy-up, so comparing
///     device+inode does *not* detect it: the path's identity is unchanged and
///     only the contents diverge. Observed in this image as an exporter
///     reporting `watch_coherent 0` and no segment for the pod's whole life,
///     while `qdbstat --once` in the same container read the daemon as healthy.
///
/// So identity is not a signal worth consulting: re-open unconditionally. A
/// scrape is once a scrape interval and the cost is an open, an mmap and a
/// munmap of one page — far cheaper than being wrong for a pod's lifetime.
struct ArenaWatch {
    path: PathBuf,
    mapped: Option<&'static metrics::Metrics>,
}

impl ArenaWatch {
    fn new(path: PathBuf) -> Self {
        let mut w = ArenaWatch { path, mapped: None };
        w.refresh();
        w
    }

    /// Map the arena afresh, releasing the previous mapping.
    ///
    /// The new mapping is taken *before* the old one is dropped, so a failed
    /// open (the arena momentarily absent, or seen mid-initialisation before
    /// its magic is published) leaves the last good view in place rather than
    /// blanking the scrape.
    fn refresh(&mut self) {
        let Some(fresh) = open_arena(&self.path) else { return };
        if let Some(stale) = self.mapped.replace(fresh) {
            // Safety: `stale` came from `open_arena` (i.e. `map_file`) and the
            // snapshot below reads only through `self.mapped`, which now points
            // at the new mapping.
            unsafe { metrics::unmap(stale as *const _ as *mut _) };
        }
    }

    fn snapshot(&mut self) -> (Snapshot, bool) {
        self.refresh();
        (snapshot(self.mapped), self.mapped.is_some())
    }
}

/// Serve `/metrics` until killed.
///
/// Deliberately a single-threaded accept loop with no dependencies: a scrape
/// arrives once a scrape interval, the body is a few KB built from atomic
/// loads, and the process this runs beside is the one serving the site — an
/// exporter that needs a runtime to publish its counters has the cost/benefit
/// backwards. A slow client cannot wedge it either way, because the socket
/// carries a read and a write timeout.
fn serve(addr: &str, shm_path: PathBuf, data_dir: PathBuf) -> Result<(), String> {
    use std::io::{BufRead, BufReader, Write};
    use std::net::TcpListener;

    let listener = TcpListener::bind(addr).map_err(|e| format!("cannot listen on {addr}: {e}"))?;
    eprintln!("qdbstat: serving /metrics on {addr}");
    let mut arena = ArenaWatch::new(shm_path);

    for stream in listener.incoming() {
        let mut stream = match stream {
            Ok(s) => s,
            // A failed accept is per-connection (the peer went away, or the
            // process is out of descriptors); neither is a reason to stop
            // exporting.
            Err(e) => {
                eprintln!("qdbstat: accept failed: {e}");
                continue;
            }
        };
        let timeout = Some(Duration::from_secs(10));
        let _ = stream.set_read_timeout(timeout);
        let _ = stream.set_write_timeout(timeout);

        // Only the request line is parsed. Headers are read and dropped:
        // nothing here varies by them, and the exposition format has no
        // negotiation worth honouring.
        let mut line = String::new();
        if BufReader::new(&stream).read_line(&mut line).is_err() {
            continue;
        }
        let mut parts = line.split_whitespace();
        let method = parts.next().unwrap_or("");
        let path = parts.next().unwrap_or("");
        let path = path.split('?').next().unwrap_or(path);

        let response = match (method, path) {
            ("GET" | "HEAD", "/metrics") => {
                let (s, arena_ok) = arena.snapshot();
                let seg = seg_stats(&data_dir, s.data_epoch);
                let fs = shm_fs(&data_dir);
                let body = to_prometheus(&s, &seg, &fs, arena_ok);
                http(200, "text/plain; version=0.0.4; charset=utf-8", &body, method == "HEAD")
            }
            // A liveness answer for the exporter itself, distinct from the
            // health of what it measures: this says the process is up, the
            // metrics say whether the index is.
            ("GET" | "HEAD", "/healthz") => http(200, "text/plain; charset=utf-8", "ok\n", method == "HEAD"),
            ("GET" | "HEAD", "/") => http(
                200,
                "text/html; charset=utf-8",
                "<html><body><a href=\"/metrics\">/metrics</a></body></html>\n",
                method == "HEAD",
            ),
            ("GET" | "HEAD", _) => http(404, "text/plain; charset=utf-8", "not found\n", method == "HEAD"),
            _ => http(405, "text/plain; charset=utf-8", "method not allowed\n", false),
        };
        let _ = stream.write_all(response.as_bytes());
        let _ = stream.flush();
    }
    Ok(())
}

/// A complete HTTP/1.1 response. `Connection: close` because the loop serves
/// one request per connection, and a keep-alive we do not honour would leave
/// the scraper waiting out its own timeout on every scrape.
fn http(status: u16, content_type: &str, body: &str, head_only: bool) -> String {
    let reason = match status {
        200 => "OK",
        404 => "Not Found",
        405 => "Method Not Allowed",
        _ => "Error",
    };
    let head = format!(
        "HTTP/1.1 {status} {reason}\r\nContent-Type: {content_type}\r\nContent-Length: {}\r\nConnection: close\r\n\r\n",
        body.len()
    );
    if head_only {
        head
    } else {
        head + body
    }
}

/// Process exit status for `--exit-code`, in the convention monitoring tooling
/// already speaks (Nagios/`check_*`): 0 OK, 1 WARNING, 2 CRITICAL, 3 UNKNOWN.
/// Without the flag every mode exits 0, which is what the existing scripted
/// callers parsing `--json` expect.
fn exit_code(health: Health) -> i32 {
    match health {
        Health::Healthy => 0,
        Health::Degraded => 1,
        Health::Fallback => 2,
        Health::Unknown => 3,
    }
}

/// `/api/stats` for the dashboard: the same object `--json` prints.
///
/// The dashboard does not build this itself because the snapshot is assembled
/// from this binary's verdict and derivation code; it takes a function instead,
/// so the module stays independent of its host. The arena is mapped and
/// unmapped per call — a server cannot use `open_arena`'s deliberate leak.
fn dashboard_stats(shm_path: &Path, data_dir: &Path) -> String {
    let arena = dashboard::ArenaMap::open(shm_path);
    let s = arena.snapshot();
    let seg = seg_stats(data_dir, s.data_epoch);
    let fs = shm_fs(data_dir);
    to_json(&s, &seg, &fs, arena.get().is_some())
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

    // Serving comes before the one-shot modes because neither wants an arena
    // mapped up front: both may start before the first worker has created one.
    if let Some(addr) = args.listen {
        if let Err(e) = serve(&addr, shm_path, data_dir) {
            eprintln!("qdbstat: {e}");
            std::process::exit(2);
        }
        return;
    }

    if let Some(addr) = args.dashboard {
        let cfg = dashboard::Config {
            addr,
            shm_path,
            data_dir,
            token: std::env::var("QUANTA_DB_DASHBOARD_TOKEN")
                .ok()
                .filter(|v| !v.is_empty()),
            base_path: dashboard::normalize_base(&args.base_path),
        };
        if let Err(e) = dashboard::serve(cfg, dashboard_stats) {
            eprintln!("qdbstat: {e}");
            std::process::exit(2);
        }
        return;
    }

    let arena = open_arena(&shm_path);
    let arena_ok = arena.is_some();
    let col = color_enabled(args.no_color);

    // Every one-shot mode exits 0 unless --exit-code was asked for, so the
    // scripts that already parse --json keep working unchanged.
    let finish = |health: Health| {
        if args.exit_code {
            std::process::exit(exit_code(health));
        }
    };

    if args.json {
        let s = snapshot(arena);
        let seg = seg_stats(&data_dir, s.data_epoch);
        let fs = shm_fs(&data_dir);
        println!("{}", to_json(&s, &seg, &fs, arena_ok));
        finish(verdict(&s, &seg, arena_ok));
        return;
    }

    if args.prometheus {
        let s = snapshot(arena);
        let seg = seg_stats(&data_dir, s.data_epoch);
        let fs = shm_fs(&data_dir);
        print!("{}", to_prometheus(&s, &seg, &fs, arena_ok));
        finish(verdict(&s, &seg, arena_ok));
        return;
    }

    if args.once {
        let s = snapshot(arena);
        let seg = seg_stats(&data_dir, s.data_epoch);
        let fs = shm_fs(&data_dir);
        print!("{}", render(&s, &seg, &fs, None, arena_ok, col));
        finish(verdict(&s, &seg, arena_ok));
        return;
    }

    // Live mode: redraw each interval with per-interval deltas.
    let interval = Duration::from_secs_f64(args.interval);
    let mut prev: Option<(Snapshot, Instant)> = None;
    loop {
        let now = Instant::now();
        let s = snapshot(arena);
        let seg = seg_stats(&data_dir, s.data_epoch);
        let fs = shm_fs(&data_dir);
        let prev_ref = prev
            .as_ref()
            .map(|(ps, pt)| (ps, now.duration_since(*pt).as_secs_f64()));
        print!("\x1b[2J\x1b[H{}", render(&s, &seg, &fs, prev_ref, arena_ok, col));
        use std::io::Write;
        let _ = std::io::stdout().flush();
        prev = Some((s, now));
        std::thread::sleep(interval);
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

#[cfg(test)]
mod verdict_tests {
    use super::*;

    /// A pod whose daemon is coherent and whose counters are all clean.
    fn healthy_snapshot() -> Snapshot {
        Snapshot {
            watch_coherent: 1,
            watch_heartbeat_unix: now_unix(),
            index_serves: 100_000,
            reads: 100_000,
            ..Default::default()
        }
    }

    fn seg(used: u64, size: u64) -> SegStats {
        SegStats { ok: true, arena_used: used, seg_size: size, ..Default::default() }
    }

    #[test]
    fn clean_pod_is_healthy() {
        let s = healthy_snapshot();
        assert!(verdict(&s, &seg(160, 320), true) == Health::Healthy);
    }

    /// The regression this rule was rewritten for: one notify failure early in
    /// the pod's life used to pin it to DEGRADED for as long as it ran.
    #[test]
    fn healed_notify_failure_ages_out() {
        let mut s = healthy_snapshot();
        s.uds_failures = 1;
        s.uds_failure_unix = now_unix() - (UDS_WARN_SECS + 1);
        assert!(verdict(&s, &seg(160, 320), true) == Health::Healthy);
    }

    #[test]
    fn recent_notify_failure_degrades() {
        let mut s = healthy_snapshot();
        s.uds_failures = 1;
        s.uds_failure_unix = now_unix();
        assert!(verdict(&s, &seg(160, 320), true) == Health::Degraded);
    }

    /// A daemon flapping on the write path keeps landing failures inside the
    /// window, so the warning never clears between episodes.
    #[test]
    fn flapping_daemon_stays_degraded() {
        let mut s = healthy_snapshot();
        s.uds_failures = 12;
        s.uds_failure_unix = now_unix() - (UDS_WARN_SECS / 2);
        assert!(verdict(&s, &seg(160, 320), true) == Health::Degraded);
    }

    /// A failure poisons coherence at the call site, so while the episode is
    /// live the pod reads as FALLBACK — a stronger verdict than DEGRADED.
    #[test]
    fn live_episode_outranks_degraded() {
        let mut s = healthy_snapshot();
        s.watch_coherent = 0;
        s.uds_failures = 1;
        s.uds_failure_unix = now_unix();
        assert!(verdict(&s, &seg(160, 320), true) == Health::Fallback);
    }

    /// Recency must not resurrect a counter that never fired: a zero timestamp
    /// is "never", not "at the epoch".
    #[test]
    fn never_failed_is_not_recent() {
        let s = healthy_snapshot();
        assert!(!uds_recent(&s));
    }

    /// The other warn triggers must survive the rewrite untouched.
    #[test]
    fn unrelated_triggers_still_degrade() {
        let mut s = healthy_snapshot();
        s.io_errors = 1;
        assert!(verdict(&s, &seg(160, 320), true) == Health::Degraded);

        let mut s = healthy_snapshot();
        s.fallback_reads = 5_000;
        assert!(verdict(&s, &seg(160, 320), true) == Health::Degraded);

        let s = healthy_snapshot();
        assert!(verdict(&s, &seg(300, 320), true) == Health::Degraded);
    }

    /// The pod this was found on: one notify failed 23 minutes into a 100k-read
    /// life and the banner had read DEGRADED ever since. It now reads HEALTHY,
    /// and the row still carries the failure so the history is not hidden.
    #[test]
    fn render_reports_healed_failure_without_alarm() {
        let mut s = healthy_snapshot();
        s.uds_notifies = 8;
        s.uds_failures = 1;
        s.uds_failure_unix = now_unix() - 1_400;
        let out = render(&s, &seg(160, 320), &ShmFs::default(), None, true, false);
        assert!(out.contains("HEALTHY"), "banner should clear:\n{out}");
        assert!(out.contains("8 notified   1 failed (23m ago)"), "row:\n{out}");
    }

    #[test]
    fn render_flags_failure_that_is_still_happening() {
        let mut s = healthy_snapshot();
        s.uds_notifies = 8;
        s.uds_failures = 1;
        s.uds_failure_unix = now_unix();
        let out = render(&s, &seg(160, 320), &ShmFs::default(), None, true, false);
        assert!(out.contains("DEGRADED"), "banner should warn:\n{out}");
        assert!(out.contains("1 failed (0s ago)"), "row:\n{out}");
    }
}

#[cfg(test)]
mod prometheus_tests {
    use super::*;
    use std::collections::{HashMap, HashSet};

    fn healthy_snapshot() -> Snapshot {
        Snapshot {
            watch_coherent: 1,
            watch_heartbeat_unix: now_unix(),
            index_serves: 100_000,
            reads: 100_000,
            ..Default::default()
        }
    }

    fn seg(used: u64, size: u64) -> SegStats {
        SegStats { ok: true, arena_used: used, seg_size: size, ..Default::default() }
    }

    fn fs(total: u64, used: u64) -> ShmFs {
        ShmFs { ok: true, total, used, avail: total.saturating_sub(used) }
    }

    /// Sample lines keyed by their full `name{labels}` head.
    fn samples(out: &str) -> HashMap<String, f64> {
        out.lines()
            .filter(|l| !l.starts_with('#') && !l.trim().is_empty())
            .map(|l| {
                let (head, value) = l.rsplit_once(' ').expect("sample has a value");
                (head.to_string(), value.parse::<f64>().expect("value parses as a float"))
            })
            .collect()
    }

    /// The exposition format is unforgiving in ways that are invisible until a
    /// scrape fails: a family must carry HELP and TYPE before its first sample,
    /// and all of its samples must be contiguous. Both are properties of the
    /// writing order, which is exactly the kind of thing a later edit breaks.
    #[test]
    fn every_family_is_declared_once_and_kept_together() {
        let out = to_prometheus(&healthy_snapshot(), &seg(160, 320), &fs(1024, 400), true);
        let mut declared: HashSet<String> = HashSet::new();
        let mut typed: HashSet<String> = HashSet::new();
        let mut closed: HashSet<String> = HashSet::new();
        let mut current: Option<String> = None;
        for line in out.lines() {
            if let Some(rest) = line.strip_prefix("# HELP ") {
                let name = rest.split_whitespace().next().unwrap().to_string();
                assert!(declared.insert(name.clone()), "{name} declared twice");
                assert!(rest.len() > name.len() + 1, "{name} has no help text");
                continue;
            }
            if let Some(rest) = line.strip_prefix("# TYPE ") {
                let mut it = rest.split_whitespace();
                let name = it.next().unwrap().to_string();
                let kind = it.next().expect("TYPE needs a metric type");
                assert!(
                    ["counter", "gauge", "histogram", "summary", "untyped"].contains(&kind),
                    "{name} has an unknown type {kind}"
                );
                assert!(declared.contains(&name), "{name} typed before it was declared");
                typed.insert(name);
                continue;
            }
            let head = line.split(['{', ' ']).next().unwrap().to_string();
            if current.as_deref() != Some(head.as_str()) {
                if let Some(prev) = current.take() {
                    closed.insert(prev);
                }
                assert!(!closed.contains(&head), "{head} samples are not contiguous");
                assert!(typed.contains(&head), "{head} has a sample but no HELP/TYPE");
                current = Some(head);
            }
        }
    }

    /// The pair the compaction-headroom question is asked with. Neither number
    /// is derivable from the other, and the rule is worthless without both.
    #[test]
    fn segment_and_shm_capacity_are_both_exported() {
        let out = to_prometheus(&healthy_snapshot(), &seg(160, 320), &fs(1024, 400), true);
        let s = samples(&out);
        assert_eq!(s["quanta_db_segment_bytes"], 320.0);
        assert_eq!(s["quanta_db_shm_total_bytes"], 1024.0);
        assert_eq!(s["quanta_db_shm_used_bytes"], 400.0);
        assert_eq!(s["quanta_db_shm_avail_bytes"], 624.0);
        assert_eq!(s["quanta_db_segment_used_ratio"], 0.5);
    }

    /// A zero-filled family is worse than a missing one: it reads as a real
    /// measurement, and a zero denominator turns every ratio built on it into a
    /// confident wrong answer rather than no answer.
    #[test]
    fn absent_sources_are_omitted_rather_than_zeroed() {
        let out = to_prometheus(&healthy_snapshot(), &SegStats::default(), &ShmFs::default(), true);
        let s = samples(&out);
        assert!(!s.contains_key("quanta_db_segment_bytes"), "no segment -> no segment family");
        assert!(!s.contains_key("quanta_db_shm_total_bytes"), "no statvfs -> no shm family");
        // The counters that do not depend on either are still there.
        assert_eq!(s["quanta_db_reads_total"], 100_000.0);
    }

    /// Without the arena every counter would be a fabricated zero, which is
    /// indistinguishable from a genuinely idle pod.
    #[test]
    fn no_arena_exports_up_and_verdict_only() {
        let out = to_prometheus(&Snapshot::default(), &SegStats::default(), &fs(1024, 0), false);
        let s = samples(&out);
        assert_eq!(s["quanta_db_up"], 0.0);
        assert_eq!(s["quanta_db_health{state=\"unknown\"}"], 1.0);
        assert!(!s.contains_key("quanta_db_reads_total"), "counters must not be invented:\n{out}");
    }

    #[test]
    fn health_marks_exactly_one_state() {
        for (snap, seg_stats, arena_ok) in [
            (healthy_snapshot(), seg(160, 320), true),
            (Snapshot { watch_coherent: 0, ..healthy_snapshot() }, seg(160, 320), true),
            (Snapshot { io_errors: 1, ..healthy_snapshot() }, seg(160, 320), true),
            (Snapshot::default(), SegStats::default(), false),
        ] {
            let out = to_prometheus(&snap, &seg_stats, &fs(1024, 0), arena_ok);
            let on = samples(&out)
                .iter()
                .filter(|(k, v)| k.starts_with("quanta_db_health{") && **v == 1.0)
                .count();
            assert_eq!(on, 1, "exactly one state is active:\n{out}");
        }
    }

    /// A fallback episode has to be visible as counters a query can rate over a
    /// window; the lifetime share is exported beside them, not instead of them.
    #[test]
    fn fallback_is_exported_as_counters_and_a_share() {
        let mut s = healthy_snapshot();
        s.index_serves = 90;
        s.fallback_reads = 10;
        let out = to_prometheus(&s, &seg(160, 320), &fs(1024, 0), true);
        let m = samples(&out);
        assert_eq!(m["quanta_db_served_total{source=\"fallback\"}"], 10.0);
        assert_eq!(m["quanta_db_served_total{source=\"index\"}"], 90.0);
        assert_eq!(m["quanta_db_fallback_read_ratio"], 0.1);
    }

    /// Nanosecond counters are exported in seconds — the base unit Prometheus
    /// expects, and the one that makes rate() ratios read as latency.
    #[test]
    fn durations_are_exported_in_seconds() {
        let mut s = healthy_snapshot();
        s.read_ns = 2_500_000_000;
        s.read_ns_max = 19_513_416_890;
        let m = samples(&to_prometheus(&s, &seg(160, 320), &fs(1024, 0), true));
        assert_eq!(m["quanta_db_read_seconds_total"], 2.5);
        assert!((m["quanta_db_read_seconds_max"] - 19.513_417).abs() < 1e-6);
    }

    /// A heartbeat that never happened is not a heartbeat at the epoch: an age
    /// computed from zero would be ~56 years and would read as a live fault on
    /// a pod whose daemon is simply off.
    #[test]
    fn heartbeat_age_is_omitted_when_it_never_beat() {
        let s = Snapshot { reads: 1, ..Default::default() };
        let m = samples(&to_prometheus(&s, &seg(160, 320), &fs(1024, 0), true));
        assert!(!m.contains_key("quanta_db_heartbeat_age_seconds"));
        assert_eq!(m["quanta_db_heartbeat_timestamp_seconds"], 0.0);
    }

    #[test]
    fn exit_codes_follow_the_check_convention() {
        assert_eq!(exit_code(Health::Healthy), 0);
        assert_eq!(exit_code(Health::Degraded), 1);
        assert_eq!(exit_code(Health::Fallback), 2);
        assert_eq!(exit_code(Health::Unknown), 3);
    }

    /// A scraper reads exactly Content-Length bytes and then expects the
    /// connection to close; getting either wrong hangs the scrape until its
    /// own timeout rather than failing it.
    #[test]
    fn http_response_is_well_formed() {
        let r = http(200, "text/plain", "hello\n", false);
        assert!(r.starts_with("HTTP/1.1 200 OK\r\n"));
        assert!(r.contains("Content-Length: 6\r\n"));
        assert!(r.contains("Connection: close\r\n"));
        assert!(r.ends_with("\r\n\r\nhello\n"));
        // HEAD carries the same headers and no body.
        let h = http(200, "text/plain", "hello\n", true);
        assert!(h.contains("Content-Length: 6\r\n"));
        assert!(h.ends_with("\r\n\r\n"));
    }

    /// statvfs is the only source for the denominator, so a regression here is
    /// silent: the family simply stops being exported.
    #[test]
    fn statvfs_reads_a_real_mount() {
        let fs = shm_fs(Path::new("/tmp"));
        assert!(fs.ok, "/tmp should be statvfs-able");
        assert!(fs.total > 0);
        assert_eq!(fs.used + fs.avail, fs.total);
    }

    #[test]
    fn statvfs_on_a_missing_path_is_not_ok() {
        let fs = shm_fs(Path::new("/nonexistent-quanta-db-mount"));
        assert!(!fs.ok);
        assert_eq!(fs.total, 0);
    }

    /// The bug this exists for: the exporter served all-zero counters for a
    /// pod's whole life because it held a mapping of an arena that had since
    /// been replaced underneath it (an overlayfs copy-up, which keeps `st_ino`
    /// stable and so defeats any identity check). `qdbstat --once` in the same
    /// container read the daemon as healthy at the same moment.
    ///
    /// The property that fixes it is simply that a refresh re-reads the file,
    /// so a write that lands after the first scrape is visible to the second.
    #[test]
    fn a_served_scrape_sees_writes_that_land_after_the_first_one() {
        let dir = std::env::temp_dir().join(format!("qdbstat-watch-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let path = dir.join("metrics.shm");
        let _ = std::fs::remove_file(&path);

        // A writable arena, as the daemon would create it.
        let w = unsafe { metrics::map_file(&path, true, true).unwrap() };
        let mut watch = ArenaWatch::new(path.clone());
        let (first, ok) = watch.snapshot();
        assert!(ok, "the arena should be mapped");
        assert_eq!(first.watch_coherent, 0);

        unsafe { (*w).watch_coherent.store(1, std::sync::atomic::Ordering::Relaxed) };
        unsafe { (*w).data_epoch.store(7, std::sync::atomic::Ordering::Relaxed) };

        let (second, ok) = watch.snapshot();
        assert!(ok);
        assert_eq!(second.watch_coherent, 1, "a later write must be visible");
        assert_eq!(second.data_epoch, 7);

        // And the whole file being replaced is survivable too -- the case an
        // identity check was supposed to cover, and which re-opening covers
        // without needing to detect anything.
        std::fs::remove_file(&path).unwrap();
        let w2 = unsafe { metrics::map_file(&path, true, true).unwrap() };
        unsafe { (*w2).data_epoch.store(9, std::sync::atomic::Ordering::Relaxed) };
        let (third, ok) = watch.snapshot();
        assert!(ok);
        assert_eq!(third.data_epoch, 9, "a replaced arena must be picked up");

        let _ = std::fs::remove_dir_all(&dir);
    }
}
