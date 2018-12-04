<?php
namespace Quanta\Common;

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function jquery_boot($env, $vars) {
  $module_path = $env->getModulePath('jquery');
  $env->addInclude($module_path . '/assets/js/jquery.min.js');
  $env->addInclude($module_path . '/assets/js/engine.js');
}
