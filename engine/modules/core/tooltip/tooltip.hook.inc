<?php
/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function tooltip_load_includes($env, $vars) {
  $module_path = $env->getModulePath('tooltip');
  $env->addInclude($module_path . '/js/tooltip.js');
  $env->addInclude($module_path . '/css/tooltip.css');
}
