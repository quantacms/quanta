<?php
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 16/05/15
 * Time: 16:45
 */

class Cache extends DataContainer {
  public function __construct(&$env) {
    $this->env = $env;
  }

  /**
   * Returns a cached item.
   * @param $env
   * @param $type
   * @param $value
   */
  public static function get($env, $type, $item) {
    $cache = $env->getData('cached', array());
    if (isset($cache[$type][$item])) {
      return $cache[$type][$item];
    } else {
      return FALSE;
    }
  }

  /**
   * Sets a cached item.
   * @param $env
   * @param $type
   * @param $value
   */
  public static function set($env, $type, $item, $value) {
    $cache = $env->getData('cached', array());
    $cache[$type][$item] = $value;
    $env->setData('cached', $cache);
  }
}