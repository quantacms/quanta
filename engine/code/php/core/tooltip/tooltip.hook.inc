<?php
/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function tooltip_load_includes($env, $vars) {
  $env->addInclude('engine/code/php/core/tooltip/js/tooltip.js');
  $env->addInclude('engine/code/php/core/tooltip/css/tooltip.css');
}
