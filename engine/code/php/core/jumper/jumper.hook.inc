<?php
/*
 * Implements hook_init().
 */
function jumper_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/jumper/js/jumper.js');
  $vars['env']->addInclude('engine/code/php/core/jumper/css/jumper.css');
}
