use std::fs;
use std::io::{Read, Write};
use std::path::{Path, PathBuf};
use std::time::{SystemTime, UNIX_EPOCH};

use crate::config::Config;
use crate::error::DbError;

pub struct FileStat {
    pub mtime: i64,
    pub size: i64,
}

/// Directories pruned from the walk entirely — never nodes, never indexed,
/// never resolvable. Mirrors legacy `Environment::findNodePath()`, whose `find`
/// excludes only `*/_modules*` and `*.git*` (`_modules` holds module code, not
/// nodes). '.'-prefixed dirs are skipped too. NOTE: `assets`/`files` are
/// deliberately NOT here — legacy find locates nodes inside them (e.g.
/// `node=assets/img`), so they must stay path-resolvable; they are handled as
/// [`PAYLOAD_DIRS`] instead (walked for structure, documents never read).
const SKIP_DIRS: [&str; 2] = [".git", "_modules"];

/// Payload directories: walked and indexed so their subtree resolves by name
/// (parity with legacy `find`), but their documents are NEVER read into the
/// daemon's memory — they hold static/binary payload (assets: css/img/js;
/// files: uploads), not node content. Also kept out of children listings
/// (`scanDirectory` parity, see [`list_children`]).
pub const PAYLOAD_DIRS: [&str; 2] = ["assets", "files"];

/// True when `rel_path` (root-relative, '/'-joined) is a payload dir or lives
/// under one — used to suppress document loading for that whole subtree.
pub fn in_payload_subtree(rel_path: &str) -> bool {
    matches!(rel_path.split('/').next(), Some(seg) if PAYLOAD_DIRS.contains(&seg))
}

pub fn doc_file(lang: &str) -> String {
    if lang.is_empty() {
        "data.json".to_string()
    } else {
        format!("data_{lang}.json")
    }
}

fn to_filestat(md: &fs::Metadata) -> FileStat {
    use std::os::unix::fs::MetadataExt;
    FileStat {
        mtime: md.mtime(),
        size: md.size() as i64,
    }
}

pub fn stat_doc(node_path: &Path, lang: &str) -> Option<FileStat> {
    let md = fs::metadata(node_path.join(doc_file(lang))).ok()?;
    md.is_file().then(|| to_filestat(&md))
}

pub fn read_doc(node_path: &Path, lang: &str) -> Result<Option<(String, FileStat)>, DbError> {
    let p = node_path.join(doc_file(lang));
    let mut f = match fs::File::open(&p) {
        Ok(f) => f,
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => return Ok(None),
        Err(e) => return Err(e.into()),
    };
    let md = f.metadata()?;
    let mut s = String::new();
    f.read_to_string(&mut s)?;
    Ok(Some((s, to_filestat(&md))))
}

/// Contract §3 durability sequence: write tmp file in the node dir, fsync,
/// atomically rename over the target. Readers can never see a partial doc.
pub fn write_doc(node_path: &Path, lang: &str, json: &str) -> Result<FileStat, DbError> {
    let final_path = node_path.join(doc_file(lang));
    let tmp = node_path.join(format!(".{}.tmp.{}", doc_file(lang), std::process::id()));
    {
        let mut f = fs::File::create(&tmp)?;
        f.write_all(json.as_bytes())?;
        f.sync_all()?;
    }
    fs::rename(&tmp, &final_path)?;
    let md = fs::metadata(&final_path)?;
    Ok(to_filestat(&md))
}

/// Languages available for a node ('' = neutral data.json).
pub fn langs_of(node_path: &Path) -> Vec<String> {
    let mut v = Vec::new();
    if let Ok(rd) = fs::read_dir(node_path) {
        for e in rd.flatten() {
            let name = e.file_name().to_string_lossy().to_string();
            if name == "data.json" {
                v.push(String::new());
            } else if let Some(lang) = name
                .strip_prefix("data_")
                .and_then(|r| r.strip_suffix(".json"))
            {
                v.push(lang.to_string());
            }
        }
    }
    v.sort();
    v
}

/// Enumerate a node dir exactly as `QuantaDb::children` sees it, BEFORE the
/// type/hidden filters: every entry that is a directory (following symlinks),
/// excluding dotfiles and the 'files'/'assets' payload dirs, `_`-hidden names
/// INCLUDED (the caller filters). Sorted. This is deliberately NOT `skip_dir`:
/// children may include `_modules` (hidden rule only) and out-of-root symlink
/// targets — byte-parity with the legacy `read_dir` behavior is contractual.
pub fn list_children(node_path: &Path) -> Vec<(String, bool)> {
    let mut out = Vec::new();
    let Ok(rd) = fs::read_dir(node_path) else {
        return out;
    };
    for e in rd.flatten() {
        let Ok(ft) = e.file_type() else { continue };
        let name = e.file_name().to_string_lossy().to_string();
        if name.starts_with('.') || PAYLOAD_DIRS.contains(&name.as_str()) {
            continue;
        }
        let is_link = ft.is_symlink();
        let is_dir = if is_link {
            fs::metadata(e.path()).map(|m| m.is_dir()).unwrap_or(false)
        } else {
            ft.is_dir()
        };
        if !is_dir {
            continue;
        }
        out.push((name, is_link));
    }
    out.sort();
    out
}

/// A node's mtime: its data.json mtime, else the directory mtime.
pub fn node_mtime(node_path: &Path) -> i64 {
    stat_doc(node_path, "")
        .map(|s| s.mtime)
        .or_else(|| {
            fs::metadata(node_path).ok().map(|md| {
                use std::os::unix::fs::MetadataExt;
                md.mtime()
            })
        })
        .unwrap_or(0)
}

pub fn skip_dir(cfg: &Config, path: &Path, name: &str) -> bool {
    name.starts_with('.') || SKIP_DIRS.contains(&name) || cfg.is_derived_path(path)
}

/// Locate a node directory by name anywhere under the root — the fallback
/// (and self-heal source) when the index misses. Equivalent of the legacy
/// `find -type d -name <name>`; symlinks are never followed.
pub fn fs_search(cfg: &Config, name: &str) -> Option<PathBuf> {
    fn rec(cfg: &Config, dir: &Path, name: &str) -> Option<PathBuf> {
        let rd = fs::read_dir(dir).ok()?;
        for e in rd.flatten() {
            let Ok(ft) = e.file_type() else { continue };
            if ft.is_symlink() || !ft.is_dir() {
                continue;
            }
            let fname = e.file_name().to_string_lossy().to_string();
            let path = e.path();
            if skip_dir(cfg, &path, &fname) {
                continue;
            }
            if fname == name {
                return Some(path);
            }
            if let Some(found) = rec(cfg, &path, name) {
                return Some(found);
            }
        }
        None
    }
    rec(cfg, &cfg.root, name)
}

pub fn father_of(cfg: &Config, path: &Path) -> Option<String> {
    let parent = path.parent()?;
    if parent == cfg.root {
        None
    } else {
        parent.file_name().map(|s| s.to_string_lossy().to_string())
    }
}

pub struct WalkNode {
    pub name: String,
    pub path: PathBuf,
    pub father: Option<String>,
}

/// Walk all node dirs under `base` for reindex. Symlinks pointing at
/// directories under the root are collected as (container, target) links.
pub fn walk(
    cfg: &Config,
    base: &Path,
    nodes: &mut Vec<WalkNode>,
    links: &mut Vec<(String, String)>,
) {
    if base != cfg.root {
        if let Some(name) = base.file_name().map(|s| s.to_string_lossy().to_string()) {
            nodes.push(WalkNode {
                name,
                path: base.to_path_buf(),
                father: father_of(cfg, base),
            });
        }
    }
    rec(cfg, base, nodes, links);

    fn rec(
        cfg: &Config,
        dir: &Path,
        nodes: &mut Vec<WalkNode>,
        links: &mut Vec<(String, String)>,
    ) {
        let container = if dir == cfg.root {
            None
        } else {
            dir.file_name().map(|s| s.to_string_lossy().to_string())
        };
        let Ok(rd) = fs::read_dir(dir) else { return };
        for e in rd.flatten() {
            let Ok(ft) = e.file_type() else { continue };
            let fname = e.file_name().to_string_lossy().to_string();
            let path = e.path();
            if ft.is_symlink() {
                // A symlink to a node dir inside the root is a link row.
                if let (Some(container), Ok(target)) = (&container, fs::canonicalize(&path)) {
                    if target.is_dir() && target.starts_with(&cfg.root) {
                        if let Some(tname) =
                            target.file_name().map(|s| s.to_string_lossy().to_string())
                        {
                            links.push((container.clone(), tname));
                        }
                    }
                }
                continue;
            }
            if !ft.is_dir() || skip_dir(cfg, &path, &fname) {
                continue;
            }
            nodes.push(WalkNode {
                name: fname,
                path: path.clone(),
                father: father_of(cfg, &path),
            });
            rec(cfg, &path, nodes, links);
        }
    }
}

/// Move a node dir to the trashbin (contract: tmp/trashbin/<ts>/<name>),
/// falling back to copy+remove when the trashbin is on another filesystem.
pub fn move_to_trash(cfg: &Config, path: &Path) -> Result<PathBuf, DbError> {
    let ts = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0);
    let dir = cfg.trashbin_dir.join(ts.to_string());
    fs::create_dir_all(&dir)?;
    let name = path
        .file_name()
        .ok_or_else(|| DbError::Io(format!("bad node path: {}", path.display())))?;
    let mut dest = dir.join(name);
    if dest.exists() {
        dest = dir.join(format!(
            "{}-{}",
            name.to_string_lossy(),
            std::process::id()
        ));
    }
    match fs::rename(path, &dest) {
        Ok(()) => Ok(dest),
        Err(e) if e.raw_os_error() == Some(libc::EXDEV) => {
            copy_recursive(path, &dest)?;
            fs::remove_dir_all(path)?;
            Ok(dest)
        }
        Err(e) => Err(e.into()),
    }
}

fn copy_recursive(src: &Path, dst: &Path) -> Result<(), DbError> {
    fs::create_dir_all(dst)?;
    for e in fs::read_dir(src)?.flatten() {
        let ft = e.file_type()?;
        let to = dst.join(e.file_name());
        if ft.is_symlink() {
            let target = fs::read_link(e.path())?;
            std::os::unix::fs::symlink(target, to)?;
        } else if ft.is_dir() {
            copy_recursive(&e.path(), &to)?;
        } else {
            fs::copy(e.path(), to)?;
        }
    }
    Ok(())
}
