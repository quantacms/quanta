<?php
/**
 * Quanta's autoloader.
 *
 * We are implementing a custom autoloader, as PSR-4 doesn't seem to fit our needs.
 *
 * Ideas and contributions welcome at https://www.github.com/quantacms/quanta
 */
define("CLASS_MAP_FILE", $env->dir['tmp'] . '/class_map.dat');

/**
 * Example 1: Using an anonymous function as the single parameter for `spl_autoload_register`
 *
 * @see http://php.net/manual/en/functions.anonymous.php
 */
spl_autoload_register(function($class_name) {
    static $class_map;
    $split = explode('\\', $class_name);
    if (!$class_map) {
      $class_map = unserialize(file_get_contents(CLASS_MAP_FILE));
    }
    $namespace = $split[1];
    $tagname = $split[2];
    if (isset($class_map[$namespace][$tagname])) {
      if (file_exists($class_map[$namespace][$tagname])) {
        require $class_map[$namespace][$tagname];
      }
    }
  }
);

/**
 * Renders a translatable string.
 * TODO: move elsewhere.
 *
 * @param $string
 *   The string.
 * @param array $replace
 *   Replacement tokens.
 *
 * @return string
 *   The translated string.
 */
function t($string, $replace = array()) {
  return \Quanta\Common\Localization::t($string, $replace);
}