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
  $env->addInclude('engine/code/php/core/gallery/js/gallery.js');
  $env->addInclude('engine/code/php/core/gallery/css/gallery.css');
}
