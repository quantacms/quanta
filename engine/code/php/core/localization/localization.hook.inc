<?php

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 *
 */
function localization_load_includes($env, $vars) {
  $env->addInclude('engine/code/php/core/localization/css/localization.css');
}

/**
 * Implements hook_boot().
 * Checks if there is a language switch request.
 *
 * @param Environment $env
 *   The Environment.
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
 * @param array $vars
 *   An array of variables.
 */
function localization_setup($env, $vars) {
  $enabled_languages = Localization::getEnabledLanguages($env);
  Doctor::talk('Looking for enabled languages...');

  // Check if there are enabled languages already. If not, setup basic languages.
  if (empty($enabled_languages)) {
    Doctor::op('None Found. Setting up basic languages...');
    // We only use english as a basic language. TODO: could be better with a custom folder.
    $basic_languages = array(
      'en' => 'English',
    );
    // Create basic languages.
    foreach ($basic_languages as $langcode => $lang) {
      if (!is_dir($env->dir['languages'] . '/' . $langcode)) {
        NodeFactory::buildNode($env, $langcode, DIR_LANGUAGES, array(
            'title' => $lang,
            'language' => LANGUAGE_NEUTRAL,
            'status' => NODE_STATUS_PUBLISHED,
          )
        );
        Doctor::ok('...' . $lang . ' (' . $langcode . ')');
      }
    }
  }
}

/**
 * Implements hook_shadow_extra().
 *
 * Add translation links to node edit shadow form.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function localization_shadow_node_edit_extra($env, $vars) {
  $vars['html'] .= qtag_TRANSLATE_LINKS($env, NULL, array());
}
