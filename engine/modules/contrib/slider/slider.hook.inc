<?php
/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   An array of variables.
 */
function slider_load_includes($env, $vars) {
  $module_path = $env->getModulePath('slider');
  $env->addInclude($module_path . '/js/slider.js');
  $env->addInclude($module_path . '/css/slider.css');  
}
