<?php
/**
 * Tests for \Quanta\Common\Logger. Needs no site or database.
 *
 *   php tests/logger_test.php
 */
require __DIR__ . '/../src/modules/environment/classes/Common/Logger.class.php';

use Quanta\Common\Logger;

$pass = 0;
$fail = 0;

function check($cond, $label) {
  global $pass, $fail;
  if ($cond) {
    $pass++;
  }
  else {
    $fail++;
    echo "  FAIL: $label\n";
  }
}

$file = tempnam(sys_get_temp_dir(), 'logger-test');

/**
 * Runs $fn with the logger writing to a fresh file; returns the decoded lines.
 */
function capture($level, callable $fn) {
  global $file;
  file_put_contents($file, '');
  Logger::configure($level, $file);
  $fn();
  // Reconfiguring closes the stream, so everything is flushed.
  Logger::configure(NULL, 'stderr');
  $lines = array_filter(explode("\n", file_get_contents($file)), 'strlen');
  return array_map(fn($l) => json_decode($l, TRUE), array_values($lines));
}

// One JSON object per line, with the standard fields.
$out = capture('debug', function () {
  Logger::get()->info('hello');
});
check(count($out) === 1, 'one record written');
check(is_array($out[0]), 'record is valid JSON');
check($out[0]['level'] === 'info', 'level field');
check($out[0]['channel'] === 'app', 'default channel');
check($out[0]['message'] === 'hello', 'message field');
check((bool) preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}\+00:00$/', $out[0]['time']), 'RFC 3339 UTC time with microseconds');
check(!empty($out[0]['request_id']), 'request id present');
check(!isset($out[0]['context']), 'no empty context');

// Threshold filtering.
$out = capture('warning', function () {
  $log = Logger::get('filter');
  $log->debug('d');
  $log->info('i');
  $log->notice('n');
  $log->warning('w');
  $log->error('e');
  $log->critical('c');
  $log->alert('a');
  $log->emergency('em');
});
check(array_column($out, 'level') === array('warning', 'error', 'critical', 'alert', 'emergency'), 'only warning and above written');
check($out[0]['channel'] === 'filter', 'named channel');

Logger::configure('error');
check(!Logger::isEnabled(Logger::WARNING) && Logger::isEnabled(Logger::ERROR), 'isEnabled follows threshold');
Logger::configure('nonsense');
check(Logger::isEnabled(Logger::INFO) && !Logger::isEnabled(Logger::DEBUG), 'unknown threshold falls back to info');

// Placeholders and context.
$out = capture('debug', function () {
  Logger::get()->info('User {name} has {n} items, flag {f}, missing {x}', array(
    'name' => 'alice',
    'n' => 3,
    'f' => FALSE,
    'list' => array(1, 2),
  ));
});
check($out[0]['message'] === 'User alice has 3 items, flag false, missing {x}', 'PSR-3 interpolation');
check($out[0]['context']['list'] === array(1, 2), 'arrays kept as structure');

// Exceptions, objects, resources, bad UTF-8, non-finite floats.
$out = capture('debug', function () {
  $e = new \RuntimeException('outer', 7, new \LogicException('inner'));
  Logger::get()->error('boom', array(
    'exception' => $e,
    'obj' => new \stdClass(),
    'res' => fopen('php://memory', 'r'),
    'bin' => "bad \xB1 utf8",
    'inf' => INF,
    'float' => 1.0,
    'date' => new \DateTimeImmutable('2020-01-02T03:04:05+00:00'),
  ));
});
$ctx = $out[0]['context'];
check($ctx['exception']['class'] === 'RuntimeException', 'exception class');
check($ctx['exception']['message'] === 'outer' && $ctx['exception']['code'] === 7, 'exception message and code');
check(str_contains($ctx['exception']['file'], 'logger_test.php:'), 'exception location');
check(is_string($ctx['exception']['trace']), 'exception trace');
check($ctx['exception']['previous']['class'] === 'LogicException', 'previous exception');
check($ctx['obj'] === '[stdClass]', 'plain object reduced to its type');
check(str_starts_with($ctx['res'], '[resource'), 'resource reduced to its type');
check(str_starts_with($ctx['bin'], 'bad ') && $ctx['bin'] !== "bad \xB1 utf8", 'invalid UTF-8 substituted, record kept');
check($ctx['inf'] === 'INF', 'non-finite float stringified');
check($ctx['float'] === 1.0, 'float kept as float');
check($ctx['date'] === '2020-01-02T03:04:05.000+00:00', 'DateTime formatted');

// Unknown level is not lost.
$out = capture('debug', function () {
  Logger::get()->log('verbose', 'typo level');
});
check($out[0]['level'] === 'error' && $out[0]['context']['invalid_level'] === 'verbose', 'unknown level logged as error');

// Deep structures are cut, not fatal.
$out = capture('debug', function () {
  $deep = 'leaf';
  for ($i = 0; $i < 20; $i++) {
    $deep = array($deep);
  }
  Logger::get()->info('deep', array('d' => $deep));
});
check(is_array($out[0]) && str_contains(json_encode($out[0]), 'max depth reached'), 'depth limit applied');

// Messages containing newlines stay on one line.
$out = capture('debug', function () {
  Logger::get()->info("line1\nline2");
});
check(count($out) === 1 && $out[0]['message'] === "line1\nline2", 'newlines escaped, one line per record');

// Upstream request id is reused when well-formed.
$ok = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
  'require ' . var_export(realpath(__DIR__ . '/../src/modules/environment/classes/Common/Logger.class.php'), TRUE) . ';'
  . '$_SERVER["HTTP_X_REQUEST_ID"] = "abc-123";'
  . '\Quanta\Common\Logger::configure(NULL, "stdout");'
  . '\Quanta\Common\Logger::get()->info("x");'
));
check(json_decode((string) $ok, TRUE)['request_id'] === 'abc-123', 'upstream X-Request-Id reused');

// Environment configuration.
$env_out = shell_exec('QUANTA_LOG_LEVEL=error QUANTA_LOG_OUTPUT=stdout ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
  'require ' . var_export(realpath(__DIR__ . '/../src/modules/environment/classes/Common/Logger.class.php'), TRUE) . ';'
  . '\Quanta\Common\Logger::get()->warning("hidden");'
  . '\Quanta\Common\Logger::get()->error("shown");'
));
$lines = array_values(array_filter(explode("\n", (string) $env_out), 'strlen'));
check(count($lines) === 1 && json_decode($lines[0], TRUE)['message'] === 'shown', 'QUANTA_LOG_LEVEL / QUANTA_LOG_OUTPUT honoured');

// An unwritable target falls back to error_log() instead of failing.
$fallback = shell_exec(escapeshellarg(PHP_BINARY) . ' -d log_errors=1 -d error_log=/dev/stdout -d display_errors=0 -r ' . escapeshellarg(
  'require ' . var_export(realpath(__DIR__ . '/../src/modules/environment/classes/Common/Logger.class.php'), TRUE) . ';'
  . '\Quanta\Common\Logger::configure(NULL, "/nonexistent-dir/app.log");'
  . '\Quanta\Common\Logger::get()->error("rescued");'
));
check(str_contains((string) $fallback, '"message":"rescued"'), 'unwritable output falls back to error_log');

/**
 * Runs PHP code in a child process with the error handlers registered.
 *
 * The child is set up like the Docker image: PHP's own error log also goes to
 * stderr, so a record logged twice would show up there as a plain-text line.
 *
 * @return array
 *   [decoded JSON records from stderr, raw stderr, stdout, exit code].
 */
function run_with_handlers($code, array $ini = array()) {
  $ini += array(
    'display_errors' => '1',
    'error_reporting' => (string) E_ALL,
    'log_errors' => '1',
    'error_log' => '/dev/stderr',
  );
  $args = '';
  foreach ($ini as $k => $v) {
    $args .= ' -d ' . escapeshellarg("$k=$v");
  }
  $script = '<?php require ' . var_export(realpath(__DIR__ . '/../src/modules/environment/classes/Common/Logger.class.php'), TRUE) . ';'
    . '\Quanta\Common\Logger::configure("debug", "stderr");'
    . '\Quanta\Common\Logger::registerErrorHandlers();' . "\n" . $code;
  $path = tempnam(sys_get_temp_dir(), 'logger-child') . '.php';
  file_put_contents($path, $script);
  $proc = proc_open(escapeshellarg(PHP_BINARY) . $args . ' ' . escapeshellarg($path), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
  $stdout = stream_get_contents($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $status = proc_close($proc);
  unlink($path);
  $records = array();
  foreach (array_filter(explode("\n", $stderr), 'strlen') as $line) {
    $records[] = json_decode($line, TRUE);
  }
  return array($records, $stderr, $stdout, $status);
}

// A warning: logged once as JSON, still displayed, not logged again as text.
list($records, $stderr, $stdout, $status) = run_with_handlers('echo $undefined; echo "after";');
check(count($records) === 1 && is_array($records[0]), 'warning: exactly one JSON line on stderr, no plain-text duplicate');
check($records[0]['channel'] === 'php' && $records[0]['level'] === 'warning', 'warning: php channel, warning level');
check($records[0]['message'] === 'Undefined variable $undefined', 'warning: message');
check($records[0]['context']['type'] === 'E_WARNING' && str_contains($records[0]['context']['file'], '.php:2'), 'warning: type and location');
check(str_contains($stdout, 'Warning') && str_contains($stdout, 'after'), 'warning: still displayed, script continues');
check($status === 0, 'warning: exit code unchanged');

// Suppression and error_reporting are honoured.
list($records) = run_with_handlers('$x = @$undefined; @trigger_error("hidden", E_USER_WARNING);');
check($records === array(), '@-suppressed errors not logged');
list($records) = run_with_handlers('trigger_error("old", E_USER_DEPRECATED); trigger_error("kept", E_USER_NOTICE);', array('error_reporting' => (string) (E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED)));
check(count($records) === 1 && $records[0]['message'] === 'kept' && $records[0]['level'] === 'notice', 'error_reporting filters; user notice logged as notice');

// Levels follow the error type.
// (PHP 8.4+ also raises a deprecation for E_USER_ERROR itself; filtered here.)
list($records) = run_with_handlers('trigger_error("u", E_USER_ERROR);', array('error_reporting' => (string) (E_ALL & ~E_DEPRECATED)));
check($records[0]['level'] === 'error' && $records[0]['context']['type'] === 'E_USER_ERROR', 'E_USER_ERROR logged as error, once');
check(count($records) === 1, 'E_USER_ERROR not logged again at shutdown');

// A fatal error is logged at shutdown, and PHP's own handling is unchanged.
list($records, $stderr, $stdout, $status) = run_with_handlers('undefined_function();');
check(count($records) === 1 && $records[0]['level'] === 'critical' && $records[0]['context']['type'] === 'E_ERROR', 'fatal: one critical record');
check(str_contains($records[0]['message'], 'undefined_function'), 'fatal: message');
check(str_contains($stdout, 'Fatal error') && $status === 255, 'fatal: still displayed, exit code 255');

// An uncaught exception is split back into exception fields.
list($records, $stderr, $stdout, $status) = run_with_handlers(
  'function thrower() { throw new \DomainException("bad in input\nsecond line", 5); } thrower();'
);
$r = $records[0];
check(count($records) === 1 && $r['level'] === 'critical', 'uncaught: one critical record');
check($r['message'] === "Uncaught DomainException: bad in input\nsecond line", 'uncaught: message without location');
check($r['context']['exception']['class'] === 'DomainException', 'uncaught: exception class');
check($r['context']['exception']['message'] === "bad in input\nsecond line", 'uncaught: exception message');
check(str_contains($r['context']['exception']['file'], '.php:2'), 'uncaught: exception location');
check(str_contains($r['context']['exception']['trace'], 'thrower()') && !str_contains($r['context']['exception']['trace'], 'thrown'), 'uncaught: trace');
check(str_contains($stdout, 'Uncaught DomainException') && $status === 255, 'uncaught: still displayed, exit code 255');

// Running out of memory still gets logged.
list($records, , , $status) = run_with_handlers('$a = array(); while (TRUE) { $a[] = str_repeat("x", 100000); }', array('memory_limit' => '16M'));
check(count($records) === 1 && str_contains($records[0]['message'], 'memory size') && $records[0]['level'] === 'critical', 'out of memory logged');

// With display off (production), nothing reaches the output.
list($records, , $stdout) = run_with_handlers('echo $undefined; undefined_function();', array('display_errors' => '0'));
check(count($records) === 2 && $stdout === '', 'display_errors=0: logged, not displayed');

unlink($file);
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
