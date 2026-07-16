//! PHP-free so the standalone binaries (`qdbstat`, `qdbd`) can reuse it via
//! `#[path]` include. The `ext-php-rs` conversion (`DbError` -> `PhpException`)
//! lives in `lib.rs`, the only PHP-aware place.

/// Contract §7 error codes. "Not found" is never an error (null/false returns).
#[derive(Debug)]
pub enum DbError {
    Io(String),
    LockTimeout(String),
    Exists(String),
    BadArgs(String),
    CorruptJson(String),
}

impl DbError {
    pub fn code(&self) -> i32 {
        match self {
            DbError::Io(_) => 1,
            DbError::LockTimeout(_) => 2,
            DbError::Exists(_) => 3,
            DbError::BadArgs(_) => 4,
            DbError::CorruptJson(_) => 5,
        }
    }

    pub fn message(&self) -> &str {
        match self {
            DbError::Io(m)
            | DbError::LockTimeout(m)
            | DbError::Exists(m)
            | DbError::BadArgs(m)
            | DbError::CorruptJson(m) => m,
        }
    }
}

impl From<std::io::Error> for DbError {
    fn from(e: std::io::Error) -> Self {
        DbError::Io(e.to_string())
    }
}
