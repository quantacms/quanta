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
function blog_load_includes(Environment $env, array $vars) {
  $module_path = $env->getModulePath('blog');

  $env->addInclude($module_path . '/assets/css/blog.css');
}
