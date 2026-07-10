//! `qdbstat` — a varnishstat-style live monitor for the quanta_db extension.
//!
//! Reads two derived data sources for a given data root:
//!   * the shared-memory counter arena (`<base>/metrics.shm`) — op rates, cache
//!     hit ratio, average access time, lock contention, aggregated across all
//!     worker processes of one pod;
//!   * the SQLite index (`<base>/index.sqlite`, opened read-only) — node/link/doc
//!     cardinality and total document bytes.
//!
//! It links neither PHP nor the cdylib; the arena struct + path derivation are
//! shared with the extension by source include.
#![allow(dead_code)]

#[path = "../metrics.rs"]
mod metrics;
#[path = "../paths.rs"]
mod paths;

use std::path::{Path, PathBuf};
use std::time::{Duration, Instant};

use metrics::Snapshot;
use rusqlite::{Connection, OpenFlags};

const USAGE: &str = "\
qdbstat — live stats for the quanta_db extension

USAGE:
    qdbstat [OPTIONS]

OPTIONS:
    -r, --root <PATH>      Data root (default: $QUANTA_DB_ROOT). Used to locate
                           the derived metrics.shm + index.sqlite.
        --shm <PATH>       Override the metrics arena path.
        --index <PATH>     Override the SQLite index path.
    -n, --interval <SECS>  Refresh interval for live mode (default: 1).
    -1, --once             Print one snapshot and exit.
    -j, --json             Print one JSON snapshot and exit.
    -h, --help             Show this help.

Notes:
    The arena is per-pod under the pod's tmp dir; run this inside the pod you
    want to inspect (e.g. `kubectl exec <pod> -- qdbstat --once`).";

struct Args {
    root: Option<PathBuf>,
    shm: Option<PathBuf>,
    index: Option<PathBuf>,
    interval: f64,
    once: bool,
    json: bool,
}

fn parse_args() -> Result<Args, String> {
    let mut a = Args {
        root: std::env::var_os("QUANTA_DB_ROOT").map(PathBuf::from),
        shm: None,
        index: None,
        interval: 1.0,
        once: false,
        json: false,
    };
    let mut it = std::env::args().skip(1);
    while let Some(arg) = it.next() {
        let mut next = |flag: &str| it.next().ok_or_else(|| format!("{flag} needs a value"));
        match arg.as_str() {
            "-r" | "--root" => a.root = Some(PathBuf::from(next("--root")?)),
            "--shm" => a.shm = Some(PathBuf::from(next("--shm")?)),
            "--index" => a.index = Some(PathBuf::from(next("--index")?)),
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
            "-h" | "--help" => {
                println!("{USAGE}");
                std::process::exit(0);
            }
            other => return Err(format!("unknown argument: {other}")),
        }
    }
    Ok(a)
}

/// Resolve the metrics.shm and index.sqlite paths from explicit overrides or by
/// deriving the per-root base (identical to the extension's `config`).
fn resolve_paths(a: &Args) -> Result<(PathBuf, PathBuf), String> {
    if let (Some(shm), Some(index)) = (&a.shm, &a.index) {
        return Ok((shm.clone(), index.clone()));
    }
    let base = match &a.root {
        Some(root) => paths::derive_base(root)
            .map_err(|e| format!("cannot resolve root {}: {e}", root.display()))?,
        None if a.shm.is_some() && a.index.is_some() => unreachable!(),
        None => {
            return Err(
                "no data root: pass --root <PATH> or set QUANTA_DB_ROOT (or give --shm + --index)"
                    .into(),
            )
        }
    };
    let shm = a.shm.clone().unwrap_or_else(|| paths::metrics_path(&base));
    let index = a.index.clone().unwrap_or_else(|| paths::index_path(&base));
    Ok((shm, index))
}

#[derive(Default, Clone, Copy)]
struct IndexStats {
    nodes: i64,
    links: i64,
    docs: i64,
    docs_bytes: i64,
    index_bytes: u64,
    wal_bytes: u64,
}

fn index_stats(path: &Path) -> IndexStats {
    let mut s = IndexStats {
        index_bytes: std::fs::metadata(path).map(|m| m.len()).unwrap_or(0),
        wal_bytes: std::fs::metadata(path.with_extension("sqlite-wal"))
            .map(|m| m.len())
            .unwrap_or(0),
        ..Default::default()
    };
    if let Ok(conn) = Connection::open_with_flags(
        path,
        OpenFlags::SQLITE_OPEN_READ_ONLY | OpenFlags::SQLITE_OPEN_URI,
    ) {
        let q = |sql: &str| conn.query_row(sql, [], |r| r.get::<_, i64>(0)).unwrap_or(0);
        s.nodes = q("SELECT COUNT(*) FROM nodes");
        s.links = q("SELECT COUNT(*) FROM links");
        s.docs = q("SELECT COUNT(*) FROM docs");
        s.docs_bytes = q("SELECT COALESCE(SUM(size),0) FROM docs");
    }
    s
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
        return "   -  ".into();
    }
    format!("{:.3} ms", ns as f64 / count as f64 / 1e6)
}

fn ratio_pct(hit: u64, miss: u64) -> String {
    let total = hit + miss;
    if total == 0 {
        return "  -  ".into();
    }
    format!("{:.1}%", 100.0 * hit as f64 / total as f64)
}

fn per_sec(delta: u64, secs: f64) -> String {
    if secs <= 0.0 {
        return "   -  ".into();
    }
    format!("{:.1}/s", delta as f64 / secs)
}

fn render(s: &Snapshot, ix: &IndexStats, prev: Option<(&Snapshot, f64)>, arena_ok: bool) -> String {
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
    o.push_str(&format!(
        "QUANTA_DB  uptime {}h{:02}m  cache {}\n",
        uptime / 3600,
        (uptime % 3600) / 60,
        ratio_pct(s.cache_hits, s.cache_misses),
    ));
    if !arena_ok {
        o.push_str("  (metrics arena not found — counters shown as 0; index stats only)\n");
    }
    // Watcher coherence: is a live watcher keeping the index authoritative?
    let now = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0);
    let hb_age = now.saturating_sub(s.watch_heartbeat_unix);
    let coherent = s.watch_coherent == 1 && s.watch_heartbeat_unix > 0 && hb_age <= 5;
    if coherent {
        o.push_str(&format!(
            "  watcher  COHERENT ({}s ago)  epoch {}  events {}  resync {}\n",
            hb_age, s.watch_epoch, s.watch_events, s.watch_resyncs
        ));
    } else if s.watch_heartbeat_unix > 0 {
        o.push_str(&format!("  watcher  STALE ({}s ago) — falling back to walk\n", hb_age));
    } else {
        o.push_str("  watcher  none — misses self-heal via docroot walk\n");
    }
    o.push('\n');

    // Interval rates, when we have a previous sample.
    if let Some((p, secs)) = prev {
        o.push_str(&format!(
            "  reads   {:>10}   writes  {:>10}   (this interval)\n",
            per_sec(s.reads.saturating_sub(p.reads), secs),
            per_sec(s.writes.saturating_sub(p.writes), secs),
        ));
        o.push_str(&format!(
            "  r-lat   {:>10}   w-lat   {:>10}\n",
            avg_ms(
                s.read_ns.saturating_sub(p.read_ns),
                s.reads.saturating_sub(p.reads)
            ),
            avg_ms(
                s.write_ns.saturating_sub(p.write_ns),
                s.writes.saturating_sub(p.writes)
            ),
        ));
        o.push('\n');
    }

    let row = |o: &mut String, k: &str, v: String| o.push_str(&format!("  {k:<16}{v:>16}\n"));
    row(&mut o, "nodes", ix.nodes.to_string());
    row(&mut o, "links", ix.links.to_string());
    row(
        &mut o,
        "docs",
        format!("{}  ({})", ix.docs, human_bytes(ix.docs_bytes as u64)),
    );
    o.push('\n');
    row(&mut o, "reads (total)", s.reads.to_string());
    row(
        &mut o,
        "cache hit",
        format!(
            "{}  ({}/{})",
            ratio_pct(s.cache_hits, s.cache_misses),
            s.cache_hits,
            s.cache_hits + s.cache_misses
        ),
    );
    row(&mut o, "avg read", avg_ms(s.read_ns, s.reads));
    row(&mut o, "avg write", avg_ms(s.write_ns, s.writes));
    row(
        &mut o,
        "index serves",
        format!("{}  file {}", s.index_serves, s.file_reads),
    );
    row(&mut o, "bytes read", human_bytes(s.bytes_read));
    row(
        &mut o,
        "fs heals",
        format!("{}  miss {}  negcache {}", s.fs_heals, s.node_misses, s.neg_hits),
    );
    row(
        &mut o,
        "authoritative",
        format!("{}  (misses served without a walk)", s.authoritative_misses),
    );
    row(&mut o, "writes / del", format!("{} / {}", s.writes, s.deletes));
    row(&mut o, "bytes written", human_bytes(s.bytes_written));
    row(
        &mut o,
        "locks",
        format!(
            "{}  wait {}  timeout {}",
            s.lock_acquires,
            avg_ms(s.lock_wait_ns, s.lock_acquires).trim(),
            s.lock_timeouts
        ),
    );
    row(&mut o, "errors", format!("io {}  json {}", s.io_errors, s.corrupt_json));
    o.push('\n');
    row(&mut o, "index.sqlite", human_bytes(ix.index_bytes));
    row(&mut o, "index wal", human_bytes(ix.wal_bytes));
    o
}

fn to_json(s: &Snapshot, ix: &IndexStats, arena_ok: bool) -> String {
    let avg = |ns: u64, c: u64| if c == 0 { 0.0 } else { ns as f64 / c as f64 / 1e6 };
    let json = serde_json::json!({
        "arena_ok": arena_ok,
        "started_unix": s.started_unix,
        "index": {
            "nodes": ix.nodes,
            "links": ix.links,
            "docs": ix.docs,
            "docs_bytes": ix.docs_bytes,
            "index_bytes": ix.index_bytes,
            "wal_bytes": ix.wal_bytes,
        },
        "reads": s.reads,
        "read_ns": s.read_ns,
        "avg_read_ms": avg(s.read_ns, s.reads),
        "cache_hits": s.cache_hits,
        "cache_misses": s.cache_misses,
        "index_serves": s.index_serves,
        "file_reads": s.file_reads,
        "bytes_read": s.bytes_read,
        "fs_heals": s.fs_heals,
        "node_misses": s.node_misses,
        "neg_hits": s.neg_hits,
        "authoritative_misses": s.authoritative_misses,
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
    let (shm_path, index_path) = match resolve_paths(&args) {
        Ok(p) => p,
        Err(e) => {
            eprintln!("qdbstat: {e}");
            std::process::exit(2);
        }
    };

    let arena = open_arena(&shm_path);
    let arena_ok = arena.is_some();

    if args.json {
        let s = snapshot(arena);
        let ix = index_stats(&index_path);
        println!("{}", to_json(&s, &ix, arena_ok));
        return;
    }

    if args.once {
        let s = snapshot(arena);
        let ix = index_stats(&index_path);
        print!("{}", render(&s, &ix, None, arena_ok));
        return;
    }

    // Live mode: redraw each interval with per-interval deltas.
    let interval = Duration::from_secs_f64(args.interval);
    let mut prev: Option<(Snapshot, Instant)> = None;
    loop {
        let now = Instant::now();
        let s = snapshot(arena);
        let ix = index_stats(&index_path);
        let prev_ref = prev
            .as_ref()
            .map(|(ps, pt)| (ps, now.duration_since(*pt).as_secs_f64()));
        print!("\x1b[2J\x1b[H{}", render(&s, &ix, prev_ref, arena_ok));
        use std::io::Write;
        let _ = std::io::stdout().flush();
        prev = Some((s, now));
        std::thread::sleep(interval);
    }
}
