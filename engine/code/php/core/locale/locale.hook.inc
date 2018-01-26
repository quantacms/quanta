<?php

/**
 * Implements hook_load_includes().
 *
 * @param $vars
 *   Miscellaneous environment / page variables.
 */
function locale_load_includes($vars) {
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
  $vars['env']->sysdir('languages', DIR_LANGUAGES);
  if (isset($_GET['lang'])) {
    Locale::switchLanguage($vars['env'], $_GET['lang']);
  }
}

/**
 * Main setup of languages.
 * @param $vars
 */
function locale_setup($vars) {
  Doctor::talk('Setting up basic languages...');
  $basic_languages = array(
    'en' => 'English',
  );

  // TODO: select which language to use for fallback?
  $fallback_language = 'en';

  foreach ($basic_languages as $langcode => $lang) {
    if (!is_dir($vars['env']->dir['languages'] . '/' . $langcode)) {
      NodeFactory::buildNode( $vars['env'], $langcode, DIR_LANGUAGES, array(
        'title' => $lang,
        'language' => LANGUAGE_NEUTRAL,
        'status' => NODE_STATUS_PUBLISHED,
        )
      );
      Doctor::ok('...' . $lang . ' (' . $langcode . ')');
    }
  }

  if (!file_exists($vars['env']->dir['languages'] . '/fallback')) {
    Doctor::talk('No fallback language found.');
    NodeFactory::linkNodes($vars['env'], $fallback_language, '_languages', array('symlink_name' => 'fallback'));
    Doctor::talk('Fallback language set to ' . $fallback_language . '.');

  }
}

/**
 * Add translation links to node edit shadow form.
 * @param $vars
 */
function locale_shadow_extra($vars) {
  $vars['html'] .= wiki_TRANSLATE_LINKS(array(), NULL, NULL, $vars['env']);
}
