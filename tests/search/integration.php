<?php

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';
require $root . '/src/modules/environment/classes/Common/DataContainer.class.php';
require $root . '/src/modules/environment/classes/Common/Environment.class.php';
require $root . '/src/modules/search/classes/Common/ElasticSearch.class.php';

$env = new \Quanta\Common\Environment('search.test', '/home/', $root);
$env->setData('ELASTICSEARCH_HOST', 'http://127.0.0.1:19200');
$env->setData('ELASTICSEARCH_INDEX', 'quanta-test');

$search = new \Quanta\Common\ElasticSearch($env);
$search->ensureIndex();

$results = $search->search('hello');
$facets = $search->facet('category');
$deleted = $search->pruneStale('current-sync');

if (($results['hits']['hits'][0]['_source']['title'] ?? '') !== 'Home') {
  fwrite(STDERR, "Search result integration check failed\n");
  exit(1);
}

if (($facets[0]['key'] ?? '') !== 'news') {
  fwrite(STDERR, "Facet integration check failed\n");
  exit(1);
}

if ($deleted !== 2) {
  fwrite(STDERR, "Stale cleanup integration check failed\n");
  exit(1);
}

echo "Elasticsearch search, facets, and stale cleanup OK\n";
