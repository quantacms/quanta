<?php
namespace Quanta\Common;
use Quanta\Qtags\TranslateLinks;

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 *
 */
function localization_load_includes(Environment $env, array $vars) {
  $module_path = $env->getModulePath('localization');
  $env->addInclude($module_path . '/assets/css/localization.css');
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
function localization_boot(Environment $env, array $vars) {
  $env->sysdir('languages', Localization::$system_path);

  if (Localization::hasEnabledLanguage($env, $env->request[1])) {
    Localization::switchLanguage($env, $env->request[1]);
  }
  // Old approach.
  // TODO: deprecate.
  elseif (isset($_GET['lang'])) {
    Localization::switchLanguage($env, $_GET['lang']);
  }
}

/**
 * Implements hook_doctor_setup().
 *
 * Main setup of languages.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function localization_doctor_setup(Environment $env, array $vars) {
  /** @var Doctor $doctor */
  $doctor = $vars['doctor'];

  $enabled_languages = Localization::getEnabledLanguages($env);
  $doctor->talk('Looking for enabled languages...');

  // Check if there are enabled languages already. If not, setup basic languages.
  if (empty($enabled_languages)) {
    $doctor->op('None Found. Setting up basic languages...');
    // We only use english as a basic language. TODO: could be better with a custom folder.
    $basic_languages = array(
      'en' => 'English',
    );
    // Create basic languages.
    foreach ($basic_languages as $langcode => $lang) {
      if (!is_dir($env->dir['languages'] . '/' . $langcode)) {
        NodeFactory::buildNode($env, $langcode, \Quanta\Common\Localization::DIR_LANGUAGES, array(
            'title' => $lang,
            'language' => \Quanta\Common\Localization::LANGUAGE_NEUTRAL,
            'status' => \Quanta\Common\Node::NODE_STATUS_PUBLISHED,
          )
        );
        $doctor->ok('...' . $lang . ' (' . $langcode . ')');
      }
    }
  }
}

/**
 * Implements hook_shadow_node_language().
 *
 * Add translation links to node edit shadow form.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function localization_shadow_node_language(Environment $env, array $vars) {
  /** @var Shadow $shadow */
  $shadow = $vars['shadow'];
  $translate_links = new TranslateLinks($env, array(), NULL);

  $shadow->addExtra($translate_links->getHtml());
}

function localization_link_alter($env, $vars) {
  /** @var \Quanta\Qtags\Link $link */
  $link = $vars['qtag'];
  $lang = !empty($link->getAttribute('language')) ? $link->getAttribute('language') : Localization::getLanguage($env);

  // Check if a language prefix must be added to the destination URL.
  // We don't add the prefix if we are using a "session" based language negotiation.
  if (($link->getType() == 'internal') && !(empty($link->getAttribute('language')) && Localization::getLanguageNegotiation($env) != Localization::LANGUAGE_NEGOTIATION_PREFIX)) {
    $link->destination = '/' . $lang . $link->destination;
  }
}