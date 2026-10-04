#!/usr/bin/env php
<?php

if ($argc < 2) {
  fwrite(STDERR, "Usage: php src/modules/search/bin/index.php <site-host> [quanta-root]\n");
  exit(2);
}

$host = $argv[1];
$root = $argv[2] ?? dirname(__DIR__, 4);
$request_uri = '/home/';

chdir($root . '/src');
require_once 'modules/environment/classes/Common/DataContainer.class.php';
require_once 'modules/environment/classes/Common/Environment.class.php';
require_once 'modules/user/classes/Common/UserFactory.class.php';

$env = new \Quanta\Common\Environment($host, $request_uri, $root);
$vars = array();

require_once 'autoload.php';
$env->load();

if (!file_exists(CLASS_MAP_FILE)) {
  $env->mapClasses();
}

// Load site .env values (including ELASTICSEARCH_HOST / ELASTICSEARCH_INDEX).
$env->hook('boot', $vars);

// CLI requests have no HTTP headers or PHP session. Seed the anonymous actor so
// access checks use exactly the public-view permissions and never call
// getallheaders() from UserFactory::current().
$_SESSION = isset($_SESSION) && is_array($_SESSION) ? $_SESSION : array();
if (!isset($_SESSION['user'])) {
  $_SESSION['user'] = serialize(new \Quanta\Common\User(
    $env,
    \Quanta\Common\User::USER_ANONYMOUS
  ));
}

$summary = (new \Quanta\Common\ElasticSearch($env))->sync();
fwrite(
  STDOUT,
  'Indexed ' . $summary['indexed']
    . ' nodes; removed ' . $summary['deleted']
    . " stale documents.\n"
);
