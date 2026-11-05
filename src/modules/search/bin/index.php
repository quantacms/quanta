#!/usr/bin/env php
<?php
if ($argc < 2) {
    fwrite(STDERR, "Usage: php src/modules/search/bin/index.php <site-host> [quanta-root]\n");
    exit(2);
}
$host = $argv[1];
$docroot = $argv[2] ?? dirname(__DIR__, 4);
$request_uri = '/home/';
chdir($docroot . '/src');
require 'modules/environment/classes/Common/DataContainer.class.php';
require 'modules/environment/classes/Common/Environment.class.php';
require 'modules/user/classes/Common/UserFactory.class.php';
$env = new \Quanta\Common\Environment($host, $request_uri, $docroot);
require 'autoload.php';
$env->load();
if (!file_exists(CLASS_MAP_FILE)) {
    $env->mapClasses();
}
$vars = array();
$env->hook('boot', $vars);
$count = (new \Quanta\Common\ElasticSearch($env))->indexAll();
fwrite(STDOUT, "Indexed {$count} nodes into Elasticsearch.\n");
