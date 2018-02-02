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
function list_load_includes($env, $vars) {
  $env->addInclude('engine/code/php/core/list/js/list.js');
  $env->addInclude('engine/code/php/core/list/css/list.css');
}
