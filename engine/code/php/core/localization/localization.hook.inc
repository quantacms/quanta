<?php

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   An array of variables.
 *
 */
function localization_load_includes($env, $vars) {
  $env->addInclude('engine/code/php/core/locale/css/locale.css');
  $env->addInclude('engine/code/php/core/locale/js/locale.js');
}

/**
 * Implements hook_boot().
 * Checks if there is a language switch request.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   An array of variables.
 */
function localization_boot($env, $vars) {
  $env->sysdir('languages', DIR_LANGUAGES);
  if (isset($_GET['lang'])) {
    Localization::switchLanguage($env, $_GET['lang']);
  }
}

/**
 * Implements hook_setup().
 * Main setup of languages.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   An array of variables.
 */
function localization_setup($env, $vars) {
  Doctor::talk('Setting up basic languages...');
  $basic_languages = array(
    'en' => 'English',
  );

  // If a fallback language has not been set already, we assume languages are not
  // set as well (i.e. it's a new installation).
  if (!file_exists($env->dir['languages'] . '/fallback')) {
    Doctor::talk('No fallback language found.');
    $fallback_language = 'en';
    // Create basic languages.
    foreach ($basic_languages as $langcode => $lang) {
      if (!is_dir($env->dir['languages'] . '/' . $langcode)) {
        NodeFactory::buildNode( $env, $langcode, DIR_LANGUAGES, array(
            'title' => $lang,
            'language' => LANGUAGE_NEUTRAL,
            'status' => NODE_STATUS_PUBLISHED,
          )
        );
        Doctor::ok('...' . $lang . ' (' . $langcode . ')');
      }
    }

    NodeFactory::linkNodes($env, $fallback_language, '_languages', array('symlink_name' => 'fallback'));
    Doctor::talk('Fallback language set to ' . $fallback_language . '.');

  }
}

/**
 * Implements hook_shadow_extra().
 *
 * Add translation links to node edit shadow form.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   An array of variables.
 */
function localization_shadow_extra($env, $vars) {
  $vars['html'] .= qtag_TRANSLATE_LINKS($env, NULL, array());
}
