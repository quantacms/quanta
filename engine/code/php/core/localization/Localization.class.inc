<?php
define("LANGUAGE_NEUTRAL", 'und');
define("DIR_LANGUAGES", "_languages");

/**
 * Class Localization
 * This class manages languages in the system.
 */
class Localization {

  /**
   * Environment's language is always current one.
   * @param $env
   * @return mixed
   */
  public static function getLanguage($env) {

    // TODO: better handling of fallback.
    if (!empty($_SESSION['language'])) {
      $lang = $_SESSION['language'];
    }
    else {
      // TODO: we have to check that the fallback language is OK
      // without creating a loop in loading the language node...
      $lang = Localization::getFallbackLanguage($env);
      // No language set. Set the current language as the fallback language.
      $_SESSION['language'] = $lang;
    }
    return $lang;
  }

  /**
   * Environment's language is always current one.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @return string
   *   A node url.
   */
  public static function getFallbackLanguage($env) {
    return $env->linkToNode('fallback');
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
  public static function switchLanguage($env, $lang) {
    $language = NodeFactory::load($env, $lang);

    if ($language->exists) {
      $_SESSION['language'] = $lang;

      if (isset($_GET['notify'])){
        new Message($env, 'Language switched to ' . $language->getTitle());
      }
    }
    else {
      new Message($env, 'Error - this language is not enabled: ' . $lang);
    }
  }

}
