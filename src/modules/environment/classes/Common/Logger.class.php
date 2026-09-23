<?php
namespace Quanta\Common;

/**
 * Class Logger
 * Structured application logging: one JSON object per line.
 *
 * Levels and method names follow PSR-3 (the RFC 5424 severities), so call
 * sites read the same as they would against any PHP logging library:
 *
 *   Logger::get()->info('User {name} logged in', array('name' => $name));
 *   Logger::get('files_db')->warning('Walk budget exhausted', array('budget' => 2.5));
 *   Logger::get()->error('Payment failed', array('exception' => $e));
 *
 * Each record is written as a single line:
 *
 *   {"time":"2026-01-01T12:00:00.123456+00:00","level":"info","channel":"app",
 *    "message":"User alice logged in","context":{"name":"alice"},
 *    "request_id":"…","host":"…","method":"GET","uri":"/home/"}
 *
 * Configuration is read from the environment, once per process:
 *
 *   QUANTA_LOG_LEVEL   Minimum level written: debug, info, notice, warning,
 *                      error, critical, alert, emergency. Default: info.
 *   QUANTA_LOG_OUTPUT  Where records go: "stderr" (default), "stdout",
 *                      "error_log" (PHP's own error_log(), wherever php.ini
 *                      points it), or an absolute file path to append to.
 *
 * stderr is the default because that is the stream a container runtime
 * collects: under php-fpm it reaches the master through catch_workers_output,
 * and the official image's docker.conf turns off the per-line decoration that
 * would otherwise prefix — and break — the JSON. Note that php-fpm splits worker
 * output longer than its log_limit, so very large contexts should be avoided.
 *
 * registerErrorHandlers() sends PHP's own warnings, notices and fatal errors
 * (uncaught exceptions included) to the same log, on the "php" channel.
 *
 * Logging never throws and never emits PHP warnings: a failure to write falls
 * back to error_log(), and a context that cannot be encoded is replaced rather
 * than dropping the record.
 */
class Logger {
  const EMERGENCY = 'emergency';
  const ALERT = 'alert';
  const CRITICAL = 'critical';
  const ERROR = 'error';
  const WARNING = 'warning';
  const NOTICE = 'notice';
  const INFO = 'info';
  const DEBUG = 'debug';

  const DEFAULT_CHANNEL = 'app';
  const DEFAULT_LEVEL = self::INFO;

  // RFC 5424 severities: lower is more severe.
  const SEVERITY = array(
    self::EMERGENCY => 0,
    self::ALERT => 1,
    self::CRITICAL => 2,
    self::ERROR => 3,
    self::WARNING => 4,
    self::NOTICE => 5,
    self::INFO => 6,
    self::DEBUG => 7,
  );

  // How deep context arrays/objects are walked before being cut off.
  const MAX_DEPTH = 8;

  // The channel PHP's own warnings, notices and fatal errors are logged on.
  const PHP_CHANNEL = 'php';

  // Errors that end the script: no error handler sees them, only shutdown.
  const FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;

  // PHP error types, by name and level. E_STRICT is left out on purpose: it
  // is no longer raised, and naming the constant is itself deprecated.
  const PHP_ERRORS = array(
    E_ERROR => array('E_ERROR', self::CRITICAL),
    E_PARSE => array('E_PARSE', self::CRITICAL),
    E_CORE_ERROR => array('E_CORE_ERROR', self::CRITICAL),
    E_COMPILE_ERROR => array('E_COMPILE_ERROR', self::CRITICAL),
    E_USER_ERROR => array('E_USER_ERROR', self::ERROR),
    E_RECOVERABLE_ERROR => array('E_RECOVERABLE_ERROR', self::ERROR),
    E_WARNING => array('E_WARNING', self::WARNING),
    E_CORE_WARNING => array('E_CORE_WARNING', self::WARNING),
    E_COMPILE_WARNING => array('E_COMPILE_WARNING', self::WARNING),
    E_USER_WARNING => array('E_USER_WARNING', self::WARNING),
    E_NOTICE => array('E_NOTICE', self::NOTICE),
    E_USER_NOTICE => array('E_USER_NOTICE', self::NOTICE),
    E_DEPRECATED => array('E_DEPRECATED', self::NOTICE),
    E_USER_DEPRECATED => array('E_USER_DEPRECATED', self::NOTICE),
  );

  // Freed at shutdown, so a fatal "out of memory" still has room to log.
  const RESERVED_MEMORY_BYTES = 32768;

  private static $channels = array();
  private static $handlers_registered = FALSE;
  private static $handling_error = FALSE;
  private static $reserved_memory = NULL;
  private static $threshold = NULL;
  private static $output = NULL;
  private static $stream = NULL;
  private static $request_id = NULL;

  private $channel;

  /**
   * Logger constructor. Use Logger::get() instead.
   *
   * @param string $channel
   *   The channel name, identifying the subsystem that logs.
   */
  private function __construct($channel) {
    $this->channel = $channel;
  }

  /**
   * Returns the logger for a channel.
   *
   * @param string $channel
   *   The subsystem name, e.g. "files_db" or "payments".
   *
   * @return Logger
   *   The (shared) logger for that channel.
   */
  public static function get($channel = self::DEFAULT_CHANNEL) {
    if (!isset(self::$channels[$channel])) {
      self::$channels[$channel] = new self($channel);
    }
    return self::$channels[$channel];
  }

  /**
   * Overrides the environment configuration (tests, CLI tools).
   *
   * @param string|NULL $level
   *   The minimum level, or NULL to keep the current one.
   * @param string|NULL $output
   *   The output target, or NULL to keep the current one.
   */
  public static function configure($level = NULL, $output = NULL) {
    self::init();
    if ($level !== NULL) {
      self::$threshold = self::parseLevel($level);
    }
    if ($output !== NULL) {
      self::closeStream();
      self::$output = $output;
    }
  }

  /**
   * Checks whether records of a level would be written at all.
   *
   * Lets a caller skip building an expensive context for a debug record.
   *
   * @param string $level
   *   The level.
   *
   * @return bool
   *   TRUE if the level passes the configured threshold.
   */
  public static function isEnabled($level) {
    self::init();
    return isset(self::SEVERITY[$level]) && self::SEVERITY[$level] <= self::$threshold;
  }

  /**
   * Routes PHP's own errors through the logger, on the "php" channel.
   *
   * Warnings, notices and deprecations are caught by an error handler; fatal
   * errors — uncaught exceptions included, which PHP turns into one — are only
   * visible to a shutdown function, so they are picked up there.
   *
   * Everything else about PHP's error handling is left as it was: the handler
   * returns FALSE, so PHP's standard handler still runs and display_errors,
   * the @ operator, error_reporting and the 500 status on a fatal all behave
   * exactly as before. Only PHP's own error logging is switched off, because
   * it would write every error a second time, as plain text.
   *
   * Uncaught exceptions deliberately get no set_exception_handler(): once one
   * is installed PHP no longer prints the error or sets the status itself,
   * and handing the exception back to PHP from inside the handler just calls
   * the handler again.
   */
  public static function registerErrorHandlers() {
    if (self::$handlers_registered) {
      return;
    }
    self::$handlers_registered = TRUE;
    ini_set('log_errors', '0');
    set_error_handler(array(self::class, 'handleError'));
    register_shutdown_function(array(self::class, 'handleShutdown'));
    self::$reserved_memory = str_repeat(' ', self::RESERVED_MEMORY_BYTES);
  }

  /**
   * Error handler for non-fatal PHP errors. See registerErrorHandlers().
   *
   * @return bool
   *   Always FALSE, so PHP's standard handling continues.
   */
  public static function handleError($type, $message, $file = '', $line = 0) {
    // error_reporting() is also what the @ operator lowers, so this honours
    // suppressed errors. The guard stops an error raised while logging from
    // recursing back in here.
    if (!(error_reporting() & $type) || self::$handling_error) {
      return FALSE;
    }
    self::$handling_error = TRUE;
    self::get(self::PHP_CHANNEL)->log(self::phpErrorLevel($type), $message, array(
      'type' => self::phpErrorName($type),
      'file' => $file . ':' . $line,
    ));
    self::$handling_error = FALSE;
    return FALSE;
  }

  /**
   * Shutdown function that logs the fatal error ending the script, if any.
   */
  public static function handleShutdown() {
    self::$reserved_memory = NULL;
    $error = error_get_last();
    if ($error === NULL || !($error['type'] & self::FATAL_ERRORS)) {
      return;
    }
    $message = $error['message'];
    $context = array(
      'type' => self::phpErrorName($error['type']),
      'file' => $error['file'] . ':' . $error['line'],
    );
    // An uncaught exception only reaches here as the text PHP made of it:
    // "Uncaught <class>: <message> in <file>:<line>\nStack trace:\n...".
    // Split it back into the fields a logged exception has.
    if (preg_match('/^Uncaught ([\w\\\\]+)(?:: (.*?))? in ([^\n]*:\d+)\nStack trace:\n(.*?)(?:\n  thrown)?$/s', $message, $m)) {
      $message = 'Uncaught ' . $m[1] . ($m[2] !== '' ? ': ' . $m[2] : '');
      $context['exception'] = array(
        'class' => $m[1],
        'message' => $m[2],
        'file' => $m[3],
        'trace' => $m[4],
      );
    }
    self::get(self::PHP_CHANNEL)->log(self::phpErrorLevel($error['type']), $message, $context);
  }

  /**
   * The log level a PHP error type is recorded at.
   */
  private static function phpErrorLevel($type) {
    return self::PHP_ERRORS[$type][1] ?? self::ERROR;
  }

  /**
   * The constant name of a PHP error type, e.g. "E_WARNING".
   */
  private static function phpErrorName($type) {
    return self::PHP_ERRORS[$type][0] ?? 'E_UNKNOWN(' . $type . ')';
  }

  public function emergency($message, array $context = array()) {
    $this->log(self::EMERGENCY, $message, $context);
  }

  public function alert($message, array $context = array()) {
    $this->log(self::ALERT, $message, $context);
  }

  public function critical($message, array $context = array()) {
    $this->log(self::CRITICAL, $message, $context);
  }

  public function error($message, array $context = array()) {
    $this->log(self::ERROR, $message, $context);
  }

  public function warning($message, array $context = array()) {
    $this->log(self::WARNING, $message, $context);
  }

  public function notice($message, array $context = array()) {
    $this->log(self::NOTICE, $message, $context);
  }

  public function info($message, array $context = array()) {
    $this->log(self::INFO, $message, $context);
  }

  public function debug($message, array $context = array()) {
    $this->log(self::DEBUG, $message, $context);
  }

  /**
   * Writes a record.
   *
   * @param string $level
   *   One of the level constants. An unknown level is logged as an error, so
   *   a typo at a call site does not silently lose the record.
   * @param string $message
   *   The message. {key} placeholders are replaced from $context (PSR-3).
   * @param array $context
   *   Structured data. An "exception" key holding a Throwable is expanded into
   *   its class, message, code, location and trace.
   */
  public function log($level, $message, array $context = array()) {
    try {
      $level = strtolower((string) $level);
      if (!isset(self::SEVERITY[$level])) {
        $context['invalid_level'] = $level;
        $level = self::ERROR;
      }
      if (!self::isEnabled($level)) {
        return;
      }
      self::write(self::encode($this->record($level, $message, $context)));
    }
    catch (\Throwable $e) {
      // A logger that can take the request down is worse than a lost record.
      @error_log('Logger: failed to write a record: ' . $e->getMessage());
    }
  }

  /**
   * Builds the record array for one log call.
   */
  private function record($level, $message, array $context) {
    $record = array(
      'time' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP'),
      'level' => $level,
      'channel' => $this->channel,
      'message' => self::interpolate(self::stringify($message), $context),
    );
    if (!empty($context)) {
      $record['context'] = self::normalize($context, 0);
    }
    return $record + self::requestInfo();
  }

  /**
   * Request metadata attached to every record, so lines can be correlated.
   */
  private static function requestInfo() {
    if (self::$request_id === NULL) {
      // Reuse an id set upstream (proxy, load balancer) when there is one, so
      // app records join the access log; otherwise mint one for this request.
      $upstream = isset($_SERVER['HTTP_X_REQUEST_ID']) ? (string) $_SERVER['HTTP_X_REQUEST_ID'] : '';
      self::$request_id = preg_match('/^[A-Za-z0-9._-]{1,128}$/', $upstream)
        ? $upstream
        : bin2hex(random_bytes(8));
    }
    $info = array('request_id' => self::$request_id);
    if (PHP_SAPI === 'cli') {
      $info['sapi'] = 'cli';
      return $info;
    }
    foreach (array('host' => 'HTTP_HOST', 'method' => 'REQUEST_METHOD', 'uri' => 'REQUEST_URI') as $key => $server_key) {
      if (isset($_SERVER[$server_key])) {
        $info[$key] = (string) $_SERVER[$server_key];
      }
    }
    return $info;
  }

  /**
   * Replaces {key} placeholders with context values (PSR-3).
   */
  private static function interpolate($message, array $context) {
    if (strpos($message, '{') === FALSE) {
      return $message;
    }
    $replace = array();
    foreach ($context as $key => $value) {
      if ($value === NULL || is_scalar($value) || $value instanceof \Stringable) {
        $replace['{' . $key . '}'] = self::stringify($value);
      }
    }
    return strtr($message, $replace);
  }

  /**
   * Turns a scalar or Stringable into a string.
   */
  private static function stringify($value) {
    if ($value === NULL) {
      return 'null';
    }
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    if (is_scalar($value) || $value instanceof \Stringable) {
      return (string) $value;
    }
    return '[' . get_debug_type($value) . ']';
  }

  /**
   * Makes a context value safe to JSON-encode.
   */
  private static function normalize($value, $depth) {
    if ($value === NULL || is_scalar($value)) {
      if (is_float($value) && !is_finite($value)) {
        return (string) $value;
      }
      return $value;
    }
    if ($depth >= self::MAX_DEPTH) {
      return '[max depth reached]';
    }
    if ($value instanceof \Throwable) {
      return self::normalizeThrowable($value, $depth);
    }
    if ($value instanceof \DateTimeInterface) {
      return $value->format(\DateTimeInterface::RFC3339_EXTENDED);
    }
    if ($value instanceof \JsonSerializable) {
      return self::normalize($value->jsonSerialize(), $depth + 1);
    }
    if ($value instanceof \Stringable) {
      return (string) $value;
    }
    if (is_array($value)) {
      $normalized = array();
      foreach ($value as $key => $item) {
        $normalized[$key] = self::normalize($item, $depth + 1);
      }
      return $normalized;
    }
    // Other objects, resources and closures: their type is all that is safe to
    // print — public properties may carry credentials or huge graphs.
    return '[' . get_debug_type($value) . ']';
  }

  /**
   * Expands an exception, with its chain of previous exceptions.
   */
  private static function normalizeThrowable(\Throwable $e, $depth) {
    $data = array(
      'class' => get_class($e),
      'message' => $e->getMessage(),
      'code' => $e->getCode(),
      'file' => $e->getFile() . ':' . $e->getLine(),
      'trace' => $e->getTraceAsString(),
    );
    if ($e->getPrevious() !== NULL) {
      $data['previous'] = self::normalize($e->getPrevious(), $depth + 1);
    }
    return $data;
  }

  /**
   * Encodes a record as a single JSON line.
   */
  private static function encode(array $record) {
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
      | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;
    $json = json_encode($record, $flags);
    if ($json === FALSE) {
      // The context is the only part that can fail to encode; keep the rest.
      $record['context'] = array('encoding_error' => json_last_error_msg());
      $json = json_encode($record, $flags | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
    return $json . "\n";
  }

  /**
   * Sends one encoded line to the configured output.
   */
  private static function write($line) {
    if (self::$output === 'error_log') {
      error_log(rtrim($line, "\n"));
      return;
    }
    if (self::$stream === NULL) {
      $target = self::$output;
      if ($target === 'stderr' || $target === 'stdout') {
        $target = 'php://' . $target;
      }
      $stream = @fopen($target, 'a');
      self::$stream = ($stream === FALSE) ? FALSE : $stream;
    }
    if (self::$stream === FALSE || @fwrite(self::$stream, $line) === FALSE) {
      error_log(rtrim($line, "\n"));
    }
  }

  /**
   * Reads the environment configuration, once per process.
   */
  private static function init() {
    if (self::$threshold !== NULL) {
      return;
    }
    $level = getenv('QUANTA_LOG_LEVEL');
    self::$threshold = self::parseLevel($level === FALSE ? self::DEFAULT_LEVEL : $level);
    $output = getenv('QUANTA_LOG_OUTPUT');
    self::$output = ($output === FALSE || trim($output) === '') ? 'stderr' : trim($output);
  }

  /**
   * Converts a level name into its severity, falling back to the default.
   */
  private static function parseLevel($level) {
    $level = strtolower(trim((string) $level));
    return self::SEVERITY[$level] ?? self::SEVERITY[self::DEFAULT_LEVEL];
  }

  /**
   * Closes a stream opened for a previous output target.
   */
  private static function closeStream() {
    if (is_resource(self::$stream)) {
      fclose(self::$stream);
    }
    self::$stream = NULL;
  }

}
