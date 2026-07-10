use std::cell::RefCell;
use std::time::Duration;

use rusqlite::{params, Connection, OptionalExtension};

use crate::config::Config;
use crate::error::DbError;

pub struct NodeRow {
    pub name: String,
    pub path: String,
    pub father: Option<String>,
    pub generation: i64,
}

pub struct DocRow {
    pub json: String,
    pub mtime: i64,
    pub size: i64,
}

thread_local! {
    static CONN: RefCell<Option<Connection>> = const { RefCell::new(None) };
}

/// Run a closure with the per-process index connection (opened lazily).
/// SQLite in WAL mode gives many concurrent reader processes and serialized
/// writers; busy_timeout absorbs write contention.
pub fn with<T>(
    cfg: &Config,
    f: impl FnOnce(&mut Connection) -> Result<T, DbError>,
) -> Result<T, DbError> {
    CONN.with(|c| {
        let mut opt = c.borrow_mut();
        if opt.is_none() {
            *opt = Some(open(cfg)?);
        }
        f(opt.as_mut().expect("connection just opened"))
    })
}

fn open(cfg: &Config) -> Result<Connection, DbError> {
    if let Some(dir) = cfg.index_path.parent() {
        std::fs::create_dir_all(dir)?;
    }
    let conn = Connection::open(&cfg.index_path)?;
    conn.busy_timeout(Duration::from_millis(5000))?;
    let _ = conn.pragma_update(None, "journal_mode", "WAL");
    let _ = conn.pragma_update(None, "synchronous", "NORMAL");
    conn.execute_batch(SCHEMA)?;
    Ok(conn)
}

const SCHEMA: &str = "
CREATE TABLE IF NOT EXISTS nodes(
  name TEXT PRIMARY KEY,
  path TEXT NOT NULL,
  father TEXT,
  generation INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS idx_nodes_father ON nodes(father);
CREATE INDEX IF NOT EXISTS idx_nodes_path ON nodes(path);
CREATE TABLE IF NOT EXISTS docs(
  name TEXT NOT NULL,
  lang TEXT NOT NULL DEFAULT '',
  json TEXT NOT NULL,
  mtime INTEGER NOT NULL,
  size INTEGER NOT NULL,
  PRIMARY KEY(name, lang)
);
CREATE TABLE IF NOT EXISTS links(
  container TEXT NOT NULL,
  target TEXT NOT NULL,
  PRIMARY KEY(container, target)
);
CREATE INDEX IF NOT EXISTS idx_links_target ON links(target);
";

pub fn get_node(c: &Connection, name: &str) -> Result<Option<NodeRow>, DbError> {
    c.query_row(
        "SELECT name, path, father, generation FROM nodes WHERE name = ?1",
        params![name],
        |r| {
            Ok(NodeRow {
                name: r.get(0)?,
                path: r.get(1)?,
                father: r.get(2)?,
                generation: r.get(3)?,
            })
        },
    )
    .optional()
    .map_err(Into::into)
}

/// Insert or refresh a node row. Existing generation is preserved.
pub fn upsert_node(
    c: &Connection,
    name: &str,
    path: &str,
    father: Option<&str>,
) -> Result<(), DbError> {
    c.execute(
        "INSERT INTO nodes(name, path, father, generation) VALUES(?1, ?2, ?3, 1)
         ON CONFLICT(name) DO UPDATE SET path = excluded.path, father = excluded.father",
        params![name, path, father],
    )?;
    Ok(())
}

/// Bump the node generation (the authoritative cache invalidator) and return it.
pub fn bump(c: &Connection, name: &str) -> Result<i64, DbError> {
    let g = c
        .query_row(
            "UPDATE nodes SET generation = generation + 1 WHERE name = ?1 RETURNING generation",
            params![name],
            |r| r.get(0),
        )
        .optional()?;
    Ok(g.unwrap_or(0))
}

pub fn delete_node_cascade(c: &Connection, name: &str) -> Result<(), DbError> {
    c.execute("DELETE FROM nodes WHERE name = ?1", params![name])?;
    c.execute("DELETE FROM docs WHERE name = ?1", params![name])?;
    c.execute(
        "DELETE FROM links WHERE target = ?1 OR container = ?1",
        params![name],
    )?;
    Ok(())
}

pub fn get_doc(c: &Connection, name: &str, lang: &str) -> Result<Option<DocRow>, DbError> {
    c.query_row(
        "SELECT json, mtime, size FROM docs WHERE name = ?1 AND lang = ?2",
        params![name, lang],
        |r| {
            Ok(DocRow {
                json: r.get(0)?,
                mtime: r.get(1)?,
                size: r.get(2)?,
            })
        },
    )
    .optional()
    .map_err(Into::into)
}

pub fn upsert_doc(
    c: &Connection,
    name: &str,
    lang: &str,
    json: &str,
    mtime: i64,
    size: i64,
) -> Result<(), DbError> {
    c.execute(
        "INSERT INTO docs(name, lang, json, mtime, size) VALUES(?1, ?2, ?3, ?4, ?5)
         ON CONFLICT(name, lang) DO UPDATE
           SET json = excluded.json, mtime = excluded.mtime, size = excluded.size",
        params![name, lang, json, mtime, size],
    )?;
    Ok(())
}

pub fn delete_doc(c: &Connection, name: &str, lang: &str) -> Result<(), DbError> {
    c.execute(
        "DELETE FROM docs WHERE name = ?1 AND lang = ?2",
        params![name, lang],
    )?;
    Ok(())
}

pub fn insert_link(c: &Connection, container: &str, target: &str) -> Result<(), DbError> {
    c.execute(
        "INSERT OR IGNORE INTO links(container, target) VALUES(?1, ?2)",
        params![container, target],
    )?;
    Ok(())
}

pub fn delete_link(c: &Connection, container: &str, target: &str) -> Result<(), DbError> {
    c.execute(
        "DELETE FROM links WHERE container = ?1 AND target = ?2",
        params![container, target],
    )?;
    Ok(())
}

pub fn links_of_target(c: &Connection, target: &str) -> Result<Vec<String>, DbError> {
    let mut stmt =
        c.prepare("SELECT container FROM links WHERE target = ?1 ORDER BY container")?;
    let rows = stmt.query_map(params![target], |r| r.get(0))?;
    Ok(rows.collect::<Result<Vec<String>, _>>()?)
}

pub fn names_by_path_prefix(c: &Connection, base: &str) -> Result<Vec<String>, DbError> {
    let mut stmt = c.prepare(
        "SELECT name FROM nodes WHERE path LIKE ?1 || '/%' ESCAPE '\\' ORDER BY name",
    )?;
    let pattern = base.replace('\\', "\\\\").replace('%', "\\%").replace('_', "\\_");
    let rows = stmt.query_map(params![pattern], |r| r.get(0))?;
    Ok(rows.collect::<Result<Vec<String>, _>>()?)
}

pub fn names_by_prefix(c: &Connection, prefix: &str) -> Result<Vec<String>, DbError> {
    let mut stmt =
        c.prepare("SELECT name FROM nodes WHERE name LIKE ?1 || '%' ESCAPE '\\' ORDER BY name")?;
    let pattern = prefix
        .replace('\\', "\\\\")
        .replace('%', "\\%")
        .replace('_', "\\_");
    let rows = stmt.query_map(params![pattern], |r| r.get(0))?;
    Ok(rows.collect::<Result<Vec<String>, _>>()?)
}

pub fn all_names(c: &Connection) -> Result<Vec<String>, DbError> {
    let mut stmt = c.prepare("SELECT name FROM nodes ORDER BY name")?;
    let rows = stmt.query_map([], |r| r.get(0))?;
    Ok(rows.collect::<Result<Vec<String>, _>>()?)
}

pub fn count(c: &Connection, sql: &str) -> Result<i64, DbError> {
    Ok(c.query_row(sql, [], |r| r.get(0))?)
}
