<?php
/**
 * Quanta's autoloader.
 *
 * Quanta is using a custom autoloader, as PSR-4 doesn't seem to fit our needs.
 *
 * Ideas and contributions welcome at https://www.github.com/quantacms/quanta
 */
define("CLASS_MAP_FILE", $env->dir['tmp'] . '/class_map.dat');

/**
 * Each class in Quanta has a file counterpart.
 * The autoloader lets us include the class file only when the class is actually needed.
 * Classes are mapped in the static/tmp/YOURSITE/class_map.dat file.
 *
 * @param $class_name
 *   The class being loaded.
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
