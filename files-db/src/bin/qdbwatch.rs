//! `qdbwatch` — keeps the quanta_db SQLite index authoritative in real time.
//!
//! The extension's `resolve_node` walks the whole docroot on an index miss, in
//! case a legacy writer created a node the index never learned about. This
//! daemon removes that need: it watches the docroot with **inotify** and applies
//! create/delete/move events to the index within milliseconds, and publishes a
//! heartbeat + coherence flag into the shared-memory arena. While that flag is
//! fresh the extension trusts the index — a miss is a definitive absence, no
//! walk — and the PHP shim skips its legacy `find` fallback.
//!
//! Robustness: a full **periodic resync** (presence-only reconcile) runs on a
//! timer as a safety net; an inotify queue overflow triggers an immediate
//! resync; hitting the kernel watch limit (`ENOSPC`) degrades to resync-only.
//! On exit the coherence flag is cleared, so the extension falls back cleanly.
//!
//! PHP-free: reuses the crate's modules via `#[path]` include (like `qdbstat`),
//! so it links neither the cdylib nor PHP.
#![allow(dead_code)]

#[path = "../error.rs"]
mod error;
#[path = "../paths.rs"]
mod paths;
#[path = "../metrics.rs"]
mod metrics;
#[path = "../config.rs"]
mod config;
#[path = "../index.rs"]
mod index;
#[path = "../store.rs"]
mod store;
#[path = "../reindex.rs"]
mod reindex;

use std::collections::HashMap;
use std::os::unix::io::AsRawFd;
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicBool, Ordering};
use std::time::{Duration, Instant};

use inotify::{EventMask, Inotify, WatchDescriptor, WatchMask};

use config::Config;

const USAGE: &str = "\
qdbwatch — real-time inotify watcher keeping the quanta_db index authoritative

USAGE:
    qdbwatch [OPTIONS]

OPTIONS:
    -r, --root <PATH>       Data root (default: $QUANTA_DB_ROOT).
        --resync-secs <N>   Full safety-net reconcile interval (default: 60).
    -1, --once              Snapshot + establish coherence, print status, exit.
    -v, --verbose           Log every applied event to stderr.
    -h, --help              Show this help.

The index it maintains is per-pod (under the pod's tmp dir), so run one watcher
per app container (the entrypoint does this when QUANTA_DB_WATCH is not off).";

/// Directory events we care about; `ONLYDIR` so files never get their own watch.
fn watch_mask() -> WatchMask {
    WatchMask::CREATE
        | WatchMask::DELETE
        | WatchMask::MOVED_FROM
        | WatchMask::MOVED_TO
        | WatchMask::DELETE_SELF
        | WatchMask::MOVE_SELF
        | WatchMask::ONLYDIR
}

static STOP: AtomicBool = AtomicBool::new(false);

extern "C" fn on_signal(_sig: libc::c_int) {
    STOP.store(true, Ordering::SeqCst);
}

struct Args {
    root: Option<PathBuf>,
    resync_secs: u64,
    once: bool,
    verbose: bool,
}

fn parse_args() -> Result<Args, String> {
    let mut a = Args {
        root: std::env::var_os("QUANTA_DB_ROOT").map(PathBuf::from),
        resync_secs: 60,
        once: false,
        verbose: false,
    };
    let mut it = std::env::args().skip(1);
    while let Some(arg) = it.next() {
        let mut next = |flag: &str| it.next().ok_or_else(|| format!("{flag} needs a value"));
        match arg.as_str() {
            "-r" | "--root" => a.root = Some(PathBuf::from(next("--root")?)),
            "--resync-secs" => {
                a.resync_secs = next("--resync-secs")?
                    .parse()
                    .map_err(|_| "invalid --resync-secs".to_string())?;
                if a.resync_secs == 0 {
                    return Err("--resync-secs must be > 0".into());
                }
            }
            "-1" | "--once" => a.once = true,
            "-v" | "--verbose" => a.verbose = true,
            "-h" | "--help" => {
                println!("{USAGE}");
                std::process::exit(0);
            }
            other => return Err(format!("unknown argument: {other}")),
        }
    }
    Ok(a)
}

/// One inotify event, copied to owned data so we can mutate the watch set while
/// processing (the borrow on the read buffer is released first).
struct Ev {
    wd: WatchDescriptor,
    mask: EventMask,
    name: Option<String>,
}

/// Add a watch on `dir` and recurse into its (non-skipped) subdirectories.
/// Sets `degraded` and stops adding on `ENOSPC` (kernel watch limit).
fn add_watches(
    inotify: &mut Inotify,
    cfg: &Config,
    dir: &Path,
    map: &mut HashMap<WatchDescriptor, PathBuf>,
    degraded: &mut bool,
) {
    if *degraded {
        return;
    }
    match inotify.watches().add(dir, watch_mask()) {
        Ok(wd) => {
            map.insert(wd, dir.to_path_buf());
        }
        Err(e) if e.raw_os_error() == Some(libc::ENOSPC) => {
            eprintln!(
                "qdbwatch: kernel inotify watch limit hit (max_user_watches); \
                 degrading to periodic resync only. Raise fs.inotify.max_user_watches."
            );
            *degraded = true;
            return;
        }
        Err(_) => return, // dir vanished mid-scan, or not a dir — ignore
    }
    let Ok(rd) = std::fs::read_dir(dir) else { return };
    for e in rd.flatten() {
        let Ok(ft) = e.file_type() else { continue };
        if !ft.is_dir() {
            continue; // is_dir() is false for symlinks — good, mirror walk/skip
        }
        let path = e.path();
        let name = e.file_name().to_string_lossy().to_string();
        if store::skip_dir(cfg, &path, &name) {
            continue;
        }
        add_watches(inotify, cfg, &path, map, degraded);
        if *degraded {
            return;
        }
    }
}

/// A directory appeared: index its whole subtree (presence + links) and watch it.
fn on_dir_added(inotify: &mut Inotify, cfg: &Config, map: &mut HashMap<WatchDescriptor, PathBuf>, path: &Path) {
    let mut nodes = Vec::new();
    let mut links = Vec::new();
    store::walk(cfg, path, &mut nodes, &mut links); // includes `path` itself + descendants
    let _ = index::with(cfg, |c| {
        let tx = c.transaction()?;
        for n in &nodes {
            index::upsert_node(&tx, &n.name, &n.path.to_string_lossy(), n.father.as_deref())?;
        }
        for (container, target) in &links {
            index::insert_link(&tx, container, target)?;
        }
        tx.commit()?;
        Ok(())
    });
    let mut degraded = false;
    add_watches(inotify, cfg, path, map, &mut degraded);
}

/// A directory vanished: drop it and its whole subtree from the index and prune
/// any watches under it (the kernel auto-removes watches for a deleted inode;
/// this also covers moved-out subtrees).
fn on_dir_removed(cfg: &Config, map: &mut HashMap<WatchDescriptor, PathBuf>, path: &Path) {
    let path_s = path.to_string_lossy().to_string();
    let _ = index::with(cfg, |c| {
        let tx = c.transaction()?;
        for name in index::names_by_path_prefix(&tx, &path_s)? {
            index::delete_node_cascade(&tx, &name)?;
        }
        if let Some(nm) = path.file_name() {
            index::delete_node_cascade(&tx, &nm.to_string_lossy())?;
        }
        tx.commit()?;
        Ok(())
    });
    map.retain(|_, p| !p.starts_with(path));
}

/// Full presence reconcile + refresh the watch set. Used at overflow and on the
/// periodic timer. Prunes watches for vanished dirs and (re)adds any new ones.
fn full_resync(inotify: &mut Inotify, cfg: &Config, map: &mut HashMap<WatchDescriptor, PathBuf>, degraded: &mut bool) {
    let _ = reindex::reconcile(cfg);
    map.retain(|_, p| p.exists());
    add_watches(inotify, cfg, &cfg.root, map, degraded);
    metrics::bump_epoch();
    metrics::record_resync();
}

fn main() {
    let args = match parse_args() {
        Ok(a) => a,
        Err(e) => {
            eprintln!("qdbwatch: {e}\n\n{USAGE}");
            std::process::exit(2);
        }
    };
    if let Some(root) = &args.root {
        std::env::set_var("QUANTA_DB_ROOT", root);
    }

    let cfg = match config::build_with(|_name| None) {
        Ok(c) => c,
        Err(e) => {
            eprintln!("qdbwatch: {e}");
            std::process::exit(1);
        }
    };

    // Map the shared arena writable so we can publish the heartbeat/coherence.
    metrics::init(&cfg.metrics_path, true);

    unsafe {
        libc::signal(libc::SIGTERM, on_signal as *const () as usize);
        libc::signal(libc::SIGINT, on_signal as *const () as usize);
    }

    let mut inotify = match Inotify::init() {
        Ok(i) => i,
        Err(e) => {
            eprintln!("qdbwatch: inotify init failed: {e}");
            std::process::exit(1);
        }
    };
    // Non-blocking so read_events never blocks; we drive timing with poll().
    let fd = inotify.as_raw_fd();
    unsafe {
        let flags = libc::fcntl(fd, libc::F_GETFL);
        libc::fcntl(fd, libc::F_SETFL, flags | libc::O_NONBLOCK);
    }

    // Watch BEFORE the snapshot so anything created during the scan still fires
    // an event (idempotent upserts absorb the overlap).
    let mut map: HashMap<WatchDescriptor, PathBuf> = HashMap::new();
    let mut degraded = false;
    add_watches(&mut inotify, &cfg, &cfg.root, &mut map, &mut degraded);
    let counts = reindex::reconcile(&cfg);
    metrics::set_heartbeat();
    metrics::bump_epoch();
    metrics::set_coherent(true);

    let n = counts.map(|c| c.nodes).unwrap_or(0);
    println!(
        "qdbwatch: watching {} ({} nodes, {} dir watches{})",
        cfg.root.display(),
        n,
        map.len(),
        if degraded { ", DEGRADED: resync-only" } else { "" }
    );

    if args.once {
        metrics::set_coherent(false);
        return;
    }

    let resync = Duration::from_secs(args.resync_secs);
    let mut last_resync = Instant::now();
    let mut buffer = [0u8; 8192];

    while !STOP.load(Ordering::SeqCst) {
        // Wait up to 1s for events; the timeout also paces heartbeat + resync.
        let mut pfd = libc::pollfd { fd, events: libc::POLLIN, revents: 0 };
        let r = unsafe { libc::poll(&mut pfd, 1, 1000) };

        if r > 0 && (pfd.revents & libc::POLLIN) != 0 {
            let evs: Vec<Ev> = match inotify.read_events(&mut buffer) {
                Ok(events) => events
                    .map(|e| Ev {
                        wd: e.wd.clone(),
                        mask: e.mask,
                        name: e.name.map(|n| n.to_string_lossy().into_owned()),
                    })
                    .collect(),
                Err(_) => Vec::new(), // WouldBlock or transient — skip
            };
            for ev in evs {
                if ev.mask.contains(EventMask::Q_OVERFLOW) {
                    if args.verbose {
                        eprintln!("qdbwatch: inotify queue overflow — full resync");
                    }
                    full_resync(&mut inotify, &cfg, &mut map, &mut degraded);
                    last_resync = Instant::now();
                    continue;
                }
                if ev.mask.contains(EventMask::IGNORED) {
                    map.remove(&ev.wd); // watch auto-removed (dir gone)
                    continue;
                }
                let Some(dir) = map.get(&ev.wd).cloned() else { continue };
                let Some(name) = ev.name else { continue };
                if !ev.mask.contains(EventMask::ISDIR) {
                    continue; // only node directories matter (ignore data.json etc.)
                }
                let path = dir.join(&name);
                if store::skip_dir(&cfg, &path, &name) {
                    continue;
                }
                if ev.mask.intersects(EventMask::CREATE | EventMask::MOVED_TO) {
                    on_dir_added(&mut inotify, &cfg, &mut map, &path);
                    metrics::record_watch_event();
                    if args.verbose {
                        eprintln!("qdbwatch: + {}", path.display());
                    }
                } else if ev.mask.intersects(EventMask::DELETE | EventMask::MOVED_FROM) {
                    on_dir_removed(&cfg, &mut map, &path);
                    metrics::record_watch_event();
                    if args.verbose {
                        eprintln!("qdbwatch: - {}", path.display());
                    }
                }
            }
        }

        metrics::set_heartbeat();
        if last_resync.elapsed() >= resync {
            full_resync(&mut inotify, &cfg, &mut map, &mut degraded);
            last_resync = Instant::now();
        }
    }

    metrics::set_coherent(false);
    println!("qdbwatch: stopping (coherence cleared)");
}
