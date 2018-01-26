<?php
/**
 * Implements hook_load_includes().
 *
 * @param $vars
 *   Miscellaneous environment / page variables.
 */
function tooltip_load_includes($vars) {
  $vars['env']->addInclude('engine/code/php/core/tooltip/js/tooltip.js');
  $vars['env']->addInclude('engine/code/php/core/tooltip/css/tooltip.css');
}
