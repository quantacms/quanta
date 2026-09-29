<?php
/**
 * Request data must stay data across the real QTag substitution passes.
 *
 *   php tests/query_string_qtag_test.php
 */
require __DIR__ . '/../src/modules/environment/classes/Common/DataContainer.class.php';
require __DIR__ . '/../src/modules/environment/classes/Common/Environment.class.php';
require __DIR__ . '/../src/modules/cache/classes/Common/Cacheable.class.php';
require __DIR__ . '/../src/modules/cache/classes/Common/Cache.class.php';
require __DIR__ . '/../src/modules/api/classes/Common/Api.class.php';
require __DIR__ . '/../src/modules/qtags/classes/Common/QtagFactory.class.php';
require __DIR__ . '/../src/modules/qtags/classes/Qtags/Qtag.class.php';
require __DIR__ . '/../src/modules/api/classes/Qtags/QueryString.qtag.php';
require __DIR__ . '/../src/modules/environment/classes/Qtags/Env.qtag.php';
require __DIR__ . '/../src/modules/stats/hooks/stats.hook.inc';

use Quanta\Common\Environment;
use Quanta\Common\QtagFactory;

$pass = 0;
$fail = 0;
$original_request = $_REQUEST;

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

function render_request($value, $json = FALSE) {
  $_REQUEST = array('input' => $value);
  $env = new Environment('qtag-test.invalid', '/home/', dirname(__DIR__));
  $env->setData('bounty_fixture', 'PRIVATE_FIXTURE_VALUE');
  $markup = $json
    ? '[QUERY_STRING|name=input|JSON=1|data=value]'
    : '[QUERY_STRING|name=input]';
  return QtagFactory::transformCodeTags($env, $markup);
}

foreach (array('[ENV|key=bounty_fixture]', '{ENV|key=bounty_fixture}') as $payload) {
  foreach (array(FALSE, TRUE) as $json) {
    $result = render_request($json ? json_encode(array('value' => $payload)) : $payload, $json);
    check(!str_contains($result, 'PRIVATE_FIXTURE_VALUE'), 'request cannot execute a nested QTag');
    check(html_entity_decode($result, ENT_QUOTES | ENT_HTML5, 'UTF-8') === $payload, 'QTag-looking input stays visible as text');
  }
}

$html = '<img src=x onerror="alert(1)"> & "quoted"';
foreach (array(FALSE, TRUE) as $json) {
  $result = render_request($json ? json_encode(array('value' => $html)) : $html, $json);
  check(!str_contains($result, '<img'), 'request HTML cannot introduce an element');
  check(!str_contains($result, '"'), 'request quotes cannot leave an HTML attribute');
  check(html_entity_decode($result, ENT_QUOTES | ENT_HTML5, 'UTF-8') === $html, 'escaping preserves the visible text');
}

check(render_request('ordinary search') === 'ordinary search', 'plain text is preserved');
check(render_request('{"value":"ordinary search"}', TRUE) === 'ordinary search', 'JSON text is preserved');

$literal = "line one\nline two\t&lbrack;ENV&verbar;key=bounty_fixture&rbrack;";
check(
  html_entity_decode(render_request($literal), ENT_QUOTES | ENT_HTML5, 'UTF-8') === $literal,
  'whitespace and existing entities stay literal'
);
check(render_request(array('[ENV|key=bounty_fixture]')) === '', 'array request cannot inject a QTag');
check(render_request(array('value' => 'text'), TRUE) === '', 'JSON mode rejects a non-string request');
foreach (array('{', 'null', '[]', '{}', '{"value":[]}', '{"value":{}}') as $invalid) {
  check(render_request($invalid, TRUE) === '', 'missing or non-scalar JSON field renders empty');
}
check(render_request(NULL) === '', 'missing request renders empty');
check(render_request('0') === '0', 'a zero-valued query parameter remains text');

// Normal template nesting must still work; only request-derived text is inert.
$_REQUEST = array('input' => 'safe');
$env = new Environment('qtag-test.invalid', '/home/', dirname(__DIR__));
$env->setData('template_fixture', '[QUERY_STRING|name=input]');
check(QtagFactory::transformCodeTags($env, '[ENV|key=template_fixture]') === 'safe', 'authored nested templates still render');

$_REQUEST = $original_request;
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
