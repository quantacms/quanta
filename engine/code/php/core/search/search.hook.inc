<?php

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function search_load_includes($env, $vars) {
  $env->addInclude('engine/code/php/core/search/css/search.css');
}
