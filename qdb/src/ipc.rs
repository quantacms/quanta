//! Unix-domain-socket protocol between the PHP extension (writer notifications)
//! and the `qdbd` daemon (single SHM writer). Framing: 4-byte LE length prefix
//! + JSON body. Traffic is write-rate only — clarity over micro-optimization.
//!
//! Ack semantics (contract §4.1): the daemon applies a mutation to the model,
//! publishes it to the shared-memory segment, and only THEN acks — so once a
//! write returns to PHP, every process's next SHM read observes it.
//!
//! PHP-free — shared by `qdbd` via `#[path]` include.
#![allow(dead_code)]

use std::cell::RefCell;
use std::io::{self, Read, Write};
use std::os::unix::net::UnixStream;
use std::path::Path;
use std::time::Duration;

use serde_json::{json, Value};

/// Upper bound on a frame; anything larger is a protocol violation.
pub const MAX_FRAME: u32 = 1 << 20;

pub fn write_frame(s: &mut impl Write, body: &[u8]) -> io::Result<()> {
    if body.len() as u32 > MAX_FRAME {
        return Err(io::Error::new(io::ErrorKind::InvalidInput, "frame too large"));
    }
    s.write_all(&(body.len() as u32).to_le_bytes())?;
    s.write_all(body)?;
    s.flush()
}

pub fn read_frame(s: &mut impl Read) -> io::Result<Vec<u8>> {
    let mut len = [0u8; 4];
    s.read_exact(&mut len)?;
    let len = u32::from_le_bytes(len);
    if len > MAX_FRAME {
        return Err(io::Error::new(io::ErrorKind::InvalidData, "frame too large"));
    }
    let mut body = vec![0u8; len as usize];
    s.read_exact(&mut body)?;
    Ok(body)
}

// ---------------------------------------------------------------------------
// Client (extension side): one lazy persistent connection per process.
// ---------------------------------------------------------------------------

thread_local! {
    static CONN: RefCell<Option<UnixStream>> = const { RefCell::new(None) };
}

fn connect(socket: &Path, timeout: Duration) -> io::Result<UnixStream> {
    let s = UnixStream::connect(socket)?;
    s.set_read_timeout(Some(timeout))?;
    s.set_write_timeout(Some(timeout))?;
    Ok(s)
}

fn try_request_on(s: &mut UnixStream, msg: &Value) -> io::Result<Value> {
    write_frame(s, msg.to_string().as_bytes())?;
    let body = read_frame(s)?;
    serde_json::from_slice(&body)
        .map_err(|e| io::Error::new(io::ErrorKind::InvalidData, format!("bad ack: {e}")))
}

/// Send one request and wait for the ack, reusing the cached connection.
/// A dead/stale connection gets exactly one reconnect attempt; any failure
/// drops the cached stream so the next call starts clean.
pub fn request(socket: &Path, timeout: Duration, msg: &Value) -> io::Result<Value> {
    CONN.with(|c| {
        let mut opt = c.borrow_mut();
        if let Some(s) = opt.as_mut() {
            match try_request_on(s, msg) {
                Ok(v) => return Ok(v),
                Err(_) => *opt = None, // stale (daemon restarted?) — reconnect below
            }
        }
        let mut s = connect(socket, timeout)?;
        match try_request_on(&mut s, msg) {
            Ok(v) => {
                *opt = Some(s);
                Ok(v)
            }
            Err(e) => Err(e),
        }
    })
}

/// Drop the cached connection (e.g. after the daemon was declared suspect).
pub fn reset() {
    CONN.with(|c| *c.borrow_mut() = None);
}

// ---------------------------------------------------------------------------
// Message constructors + ack helpers
// ---------------------------------------------------------------------------

pub fn msg_upsert(name: &str, rel_path: &str) -> Value {
    json!({"op": "upsert", "name": name, "rel_path": rel_path})
}

pub fn msg_delete(name: &str) -> Value {
    json!({"op": "delete", "name": name})
}

pub fn msg_link(container: &str, target: &str) -> Value {
    json!({"op": "link", "container": container, "target": target})
}

pub fn msg_unlink(container: &str, target: &str) -> Value {
    json!({"op": "unlink", "container": container, "target": target})
}

pub fn msg_relink(target: &str, from: &str, to: &str) -> Value {
    json!({"op": "relink", "target": target, "from": from, "to": to})
}

/// A node relocated from `from_rel` to `to_rel`, possibly under a new name.
///
/// Both paths are carried explicitly rather than looked up in the daemon's
/// model: a directory rename also fires inotify MOVED_FROM/MOVED_TO, so by the
/// time this message is handled the model may already have been updated and no
/// longer knows where the node came from. The writer does.
///
/// `containers` names every node that symlinks to it — the extension re-pointed
/// those links on disk, and only a rescan of the container's own directory can
/// re-derive the edges.
///
/// `new_name` is carried for legibility in logs; the daemon does not consume it,
/// since re-walking `to_rel` names the node from its directory anyway.
pub fn msg_move(
    name: &str,
    new_name: &str,
    from_rel: &str,
    to_rel: &str,
    containers: &[&str],
) -> Value {
    json!({
        "op": "move",
        "name": name,
        "new_name": new_name,
        "from_rel": from_rel,
        "to_rel": to_rel,
        "containers": containers,
    })
}

pub fn msg_reindex(subtree: Option<&str>) -> Value {
    match subtree {
        Some(s) => json!({"op": "reindex", "subtree": s}),
        None => json!({"op": "reindex"}),
    }
}

pub fn msg_ping() -> Value {
    json!({"op": "ping"})
}

pub fn ack_ok(ack: &Value) -> bool {
    ack.get("ok").and_then(Value::as_bool).unwrap_or(false)
}

pub fn ack_u64(ack: &Value, key: &str) -> Option<u64> {
    ack.get(key).and_then(Value::as_u64)
}
