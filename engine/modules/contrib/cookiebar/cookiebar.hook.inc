<?php
/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param $vars
 *   An array of variables.
 */
function cookiebar_load_includes($env, $vars) {
  $module_path = $env->getModulePath('cookiebar');
  $env->addInclude($module_path . '/js/jquery.cookiebar.js');
  $env->addInclude($module_path . '/css/jquery.cookiebar.css');
}
