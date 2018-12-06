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
function tooltip_load_includes(Environment $env, array $vars) {
  $module_path = $env->getModulePath('tooltip');
  $env->addInclude($module_path . '/assets/js/tooltip.js');
  $env->addInclude($module_path . '/assets/css/tooltip.css');
}
