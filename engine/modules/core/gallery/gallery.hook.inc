<?php
/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function gallery_load_includes($env, $vars) {
  $module_path = $env->getModulePath('gallery');
  $env->addInclude($module_path . '/js/gallery.js');
  $env->addInclude($module_path . '/css/gallery.css');
}
