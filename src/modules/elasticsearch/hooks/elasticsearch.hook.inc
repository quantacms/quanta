<?php
namespace Quanta\Common;

/**
 * Hook implementation for cron-based ElasticSearch node indexing (#58).
 */
function elasticsearch_cron(Environment $env, array $vars = []) {
  $engine = new ElasticSearchEngine($env);
  $dirs = $env->scanDirectoryDeep($env->dir['docroot'], '', [], FileObject::DIR_SUBDIRS);
  foreach ($dirs as $dirPath) {
    $nodeName = basename($dirPath);
    if (strpos($nodeName, '_') === 0 || strpos($nodeName, '.') === 0) {
      continue;
    }
    $node = NodeFactory::load($env, $nodeName);
    if ($node && $node->exists) {
      $engine->indexNode($node);
    }
  }
}
