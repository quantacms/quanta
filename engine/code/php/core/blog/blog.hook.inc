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
function blog_load_includes($env, $vars) {
  $env->addInclude('engine/code/php/core/blog/css/blog.css');
}
