<?php
namespace Quanta\Common;

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function gallery_load_includes(Environment $env, array $vars) {
  $module_path = $env->getModulePath('gallery');
  $env->addInclude($module_path . '/assets/js/gallery.js');
}
