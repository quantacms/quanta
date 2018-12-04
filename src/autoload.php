<?php
/**
 * Quanta's autoloader.
 *
 * We are implementing a custom autoloader, as PSR-4 doesn't seem to fit our needs.
 *
 * Ideas and contributions welcome at https://www.github.com/quantacms/quanta
 */
// Include the Environment module.
// TODO: avoid direct inclusion.
require_once('modules/environment/environment.module');

/**
 * TODO: where to define global API functions?
 * @param $string
 * @param array $replace
 * @return string
 */
function t($string, $replace = array()) {
  return \Quanta\Common\Localization::t($string, $replace);
}

/**
 * Example 1: Using an anonymous function as the single parameter for `spl_autoload_register`
 *
 * @see http://php.net/manual/en/functions.anonymous.php
 */
spl_autoload_register(function($class_name) {
    $split = explode('\\', $class_name);
    $class_map = $GLOBALS['class_map'];
    $namespace = $split[1];
    $tagname = $split[2];
    if (isset($class_map[$namespace][$tagname])) {
      if (file_exists($class_map[$namespace][$tagname])) {
        require $class_map[$namespace][$tagname];
      }
    }
  }
);

