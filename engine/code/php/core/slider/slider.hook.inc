<?php
/**
 * Implements hook_load_includes().
 *
 * @param $vars
 *   Miscellaneous environment / page variables.
 */
function slider_load_includes($vars) {
  $vars['env']->addInclude('engine/code/php/core/slider/js/slider.js');
  $vars['env']->addInclude('engine/code/php/core/slider/css/slider.css');
}
