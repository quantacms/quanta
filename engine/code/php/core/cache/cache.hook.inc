<?php
/**
 * Implements hook_boot().
 * @param $vars
 */
function cache_boot($vars) {
  if (!is_dir( $vars['env']->dir['tmp'] . '/cache')) {
    mkdir ( $vars['env']->dir['tmp'] . '/cache');
  }
}

/**
 * Main setup of languages.
 * @param $vars
 */
function cache_setup($vars) {
  $basic_languages = array(
    'en' => 'English',
  );

  foreach ($basic_languages as $langcode => $lang) {
    if (!is_dir($vars['env']->dir['languages'] . '/' . $langcode)) {
      NodeFactory::buildNode($vars['env'], $langcode, DIR_LANGUAGES, array('title' => $lang, 'language' => LANGUAGE_NEUTRAL, 'status' => NODE_STATUS_PUBLISHED));
    }
  }

  // TODO: select which language to use for fallback?
  if (!file_exists($vars['env']->dir['languages'] . '/fallback')) {
    NodeFactory::linkNodes($vars['env'], 'en', 'locale', 'fallback');
  }
}

/**
 * TODO: all deprecated but maybe useful.
 * Temporary function to regenerate all json files in the system. Handle with care!
 * @param $vars

function cache_regenerate_json($vars) {
  $wikiroot = $vars['env']->dir['wiki'];
  $scan = $vars['env']->scanDirectory($wikiroot, array(
    'exclude_dirs' => DIR_ALL,
    'type' => DIR_DIRS,
  ));
  foreach ($scan as $k => $scanned) {
    $node = new Node($vars['env'], $scanned);
    if (!$node->isEmpty()) {
      $node->save();
    }
  }
}
 */
/**
 * Cache wiki page. Implements hook_boot

function cache_boot($vars) {
  $vars['env']->setData('cache', new Cache($vars['env']));
  if (isset($_GET['cleanupjson'])) {
    cache_regenerate_json($vars);
  }
}
*/