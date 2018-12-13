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
function list_load_includes(Environment $env, array $vars) {
  $module_path = $env->getModulePath('list');

  $env->addInclude($module_path . '/assets/js/list.js');
  $env->addInclude($module_path . '/assets/css/list.css');
}
