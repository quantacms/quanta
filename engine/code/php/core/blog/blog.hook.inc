<?php
/**
 * Implements hook_load_includes().
 *
 * @param $vars
 */
function blog_load_includes($vars) {
  $vars['env']->addInclude('engine/code/php/core/blog/css/blog.css');
}
