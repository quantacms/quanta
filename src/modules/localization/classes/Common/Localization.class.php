<?php
namespace Quanta\Common;

/**
 * Class Localization
 *
 * This class manages languages negotiation in the system.
 */
class Localization {
  const LANGUAGE_NEUTRAL = "language-neutral";
  const LANGUAGE_NEGOTIATION_SESSION = "session";
  const LANGUAGE_NEGOTIATION_PREFIX = "path";
  const DIR_LANGUAGES = "_languages";
  const DIR_TRANSLATIONS = "_translations";
  public static $dir_languages = self::DIR_LANGUAGES;
  public static $dir_translations = self::DIR_TRANSLATIONS;

  /**
   * Environment's language is always current one.
   *
   * @param Environment $env
   *   The Environment.
   * @return mixed
   */
  public static function getLanguage(Environment $env) {
    if (!empty($env->getData('language'))) { 
      $lang = $env->getData('language'); 
    }
    elseif (!empty($_SESSION['language'])) {
      $lang = $_SESSION['language'];
    }
    else {
      // TODO: we have to check that the fallback language is OK
      // without creating a loop in loading the language node...
      $lang = self::getFallbackLanguage($env);
      // No language set. Set the current language as the fallback language.
      $_SESSION['language'] = $lang;
    }
    return $lang;
  }

  /**
   * Get the current language negotiation method.
   * LANGUAGE_NEGOTIATION_PREFIX will add a prefix language string
   * (i.e.) /en/ to all URLs.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @return array
   *   The languages.
   */
  public static function getLanguageNegotiation(Environment $env) {
    // If the fallback language has already been defined...
    if (empty($env->getData('language_negotiation'))) {
      $env->setData('language_negotiation', self::LANGUAGE_NEGOTIATION_PREFIX);
    }
    return $env->getData('language_negotiation');
  }

  /**
   * Get all enabled languages.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @return array
   *   The languages.
   */
  public static function getEnabledLanguages(Environment $env) {
    // If the fallback language has already been defined...
    if (empty($env->getData('enabled_languages'))) {
      $language_list = $env->scanDirectory($env->dir['languages'], array('symlinks' => 'no','value_as_key'=>TRUE));
      $env->setData('enabled_languages', $language_list);
    }
    return $env->getData('enabled_languages');
  }
    /**
   * Get the fallback language.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @return string
   *   The fallback language.
   */
  public static function getFallbackLanguage(Environment $env) {
    // If the fallback language has not been defined...
    if (empty($env->getData('fallback_language'))) {
      $vars = array();
      $vars = array('fallback_language' => NULL);
      $env->hook('fallback_language', $vars);
      // Let modules set a default fallback language.
      if (empty($vars['fallback_language'])) {
        $language_list = Localization::getEnabledLanguages($env);
	
	$fallback = array_pop($language_list);
      }
      else {
        $fallback = $vars['fallback_language'];
      }
      $env->setData('fallback_language', $fallback);
    }

    return $env->getData('fallback_language');

  }

  /**
   * Check if a language is enabled.
   *
   * @param Environment $env
   *   The Environment.
   * @param $lang
   *   The language to check.
   *
   * @return boolean
   *   TRUE if the language is enabled.
   */
  public static function hasEnabledLanguage(Environment $env, $lang) {
    $enabled_languages = self::getEnabledLanguages($env);
    $is_enabled = FALSE;
    foreach ($enabled_languages as $language) {
      if ($language == $lang) {
        $is_enabled = TRUE;
        break;
      }
    }
    return $is_enabled;
  }
  /**
   * Switch the current language.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @param string $lang
   *   A language code to switch into.
   */
  public static function switchLanguage(Environment $env, $lang) {
    if (isset($_GET['update_language'])) {
   	$lang = $_GET['update_language']; 
    }
    $language = NodeFactory::load($env, $lang);
    if ($language->exists) {

      if (isset($_GET['update_language'])) {
      	$_SESSION['language'] = $lang;
      } 

      $env->setData('language', $lang);
      
      if (isset($_GET['notify'])){
        new Message($env, 'Language switched to ' . $language->getTitle());
      }
    }
    else {
      new Message($env, 'Error - this language is not enabled: ' . $lang);
    }
  }

  /**
   * Returns a translated version of a string.
   *
   * @param string $string
   *   The translatable string.
   *
   * @param array $replace
   *   An array of replacements to perform in the string.
   *
   * @return string
   *   The translated string.
   */
  public static function t($string, array $replace = array()) {
    // TODO: multilanguage strings implementation.
    foreach ($replace as $k => $replacement) {
      $string = str_replace($k, $replacement, $string);
    }
    return $string;
  }

  public static function translatableText(Environment $env, $text, $tag = NULL, $lang = NULL) {
    if ($lang == NULL) {
    	$lang = $_SESSION['language'];
    }
    if ($tag != NULL) {
      $node = NodeFactory::load($env, $tag);
      if (!($node->exists)) {
	$attributes = array('title' => $text);
	$node = NodeFactory::buildNode($env, $tag, Localization::DIR_TRANSLATIONS, $attributes);
      }
      elseif ($node->hasTranslation($lang) && ($node->title != NULL)){
	return $node->title;
	}	

      else {
	return $text;
      }
    }
    return $text; 
  }
}
