<?php
/*
 * Implements hook_init().
 */
function tooltip_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/tooltip/js/tooltip.js');
  $vars['env']->addInclude('engine/code/php/core/tooltip/css/tooltip.css');
}
