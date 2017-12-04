<?php

/**
 * Implements hook_init().
 *
 * Create all default statuses if they don't exist yet.
 * @param $vars
 */
function locale_init($vars) {
  $vars['env']->sysdir('languages', DIR_LANGUAGES);
  $vars['env']->addInclude('engine/code/php/core/locale/css/locale.css');
  $vars['env']->addInclude('engine/code/php/core/locale/js/locale.js');
}

/**
 * Implements hook_boot.
 * Checks language switch request.
 *
 * @param $vars
 */
function locale_boot($vars) {
  if (isset($_GET['lang'])) {
    Locale::switchLanguage($vars['env'], $_GET['lang']);
  }
}

/**
 * Main setup of languages.
 * @param $vars
 */
function locale_setup($vars) {
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
 * Add translation links to node edit shadow form.
 * @param $vars
 */
function locale_shadow_extra($vars) {
  $vars['html'] .= wiki_TRANSLATE_LINKS(array(), NULL, NULL, $vars['env']);
}
