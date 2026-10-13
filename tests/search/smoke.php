<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('Elastic\\Elasticsearch\\ClientBuilder')) {
  fwrite(STDERR, "Elasticsearch client missing\n");
  exit(1);
}
foreach (array(
  'src/modules/search/classes/Common/ElasticSearch.class.php',
  'src/modules/search/classes/Qtags/Results.qtag.php',
  'src/modules/search/classes/Qtags/Facets.qtag.php',
) as $file) {
  if (!is_file(dirname(__DIR__, 2) . '/' . $file)) {
    fwrite(STDERR, "Missing {$file}\n");
    exit(1);
  }
}
echo "search module smoke OK\n";
