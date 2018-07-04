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
function slider_load_includes($env, $vars) {
  $vars['env']->addInclude('engine/code/php/core/slider/js/slider.js');
  $vars['env']->addInclude('engine/code/php/core/slider/css/slider.css');
}
