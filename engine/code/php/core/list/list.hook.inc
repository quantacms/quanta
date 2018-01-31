<?php
/**
 * Implements hook_load_includes().
 *
 * @param $vars
 *   Miscellaneous environment / page variables.
 */
function list_load_includes($vars) {
  $vars['env']->addInclude('engine/code/php/core/list/js/list.js');
  $vars['env']->addInclude('engine/code/php/core/list/css/list.css');
}
