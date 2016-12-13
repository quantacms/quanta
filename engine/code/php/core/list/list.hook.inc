<?php
/*
 * Implements hook_init().
 */
function list_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/list/js/list.js');
}
