<?php
/*
 * Implements hook_init().
 */
function zjumper_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/zjumper/js/jumper.js');
  $vars['env']->addInclude('engine/code/php/core/zjumper/css/jumper.css');
}
