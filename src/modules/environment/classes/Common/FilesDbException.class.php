<?php

namespace Quanta\Common;

/**
 * A node database failure — I/O, locking, arguments, corruption.
 *
 * Never "not found": an absent node is a return value (FALSE, NULL or an empty
 * array, depending on the method), not an exception. This is the contract's own
 * rule (qdb/docs/quanta_db.stub.php) and both implementations obey it.
 *
 * This class exists because \QuantaDbException does NOT. It ships with the
 * native extension, so on a host without the .so every `catch
 * (\QuantaDbException $e)` is a catch on an undefined class — harmless while
 * the try block could never throw it, and a fatal the moment the filesystem
 * implementation started raising failures of its own. FilesDbExt wraps the
 * extension's exception in this one, preserving the code, so a call site
 * catches one class and reads one set of constants no matter who threw.
 *
 * The codes are the contract's, by value, so a wrapped exception keeps the
 * number it arrived with.
 *
 * @see qdb/docs/api-contract.md
 */
class FilesDbException extends \RuntimeException {

  const IO = 1;
  const LOCK_TIMEOUT = 2;
  const EXISTS = 3;
  const BAD_ARGS = 4;
  const CORRUPT_JSON = 5;

}
