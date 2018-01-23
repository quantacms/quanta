<?php

/**
 * Implements hook_load_includes().
 *
 * @param $vars
 *   Miscellaneous environment / page variables.
 */
function search_load_includes($vars) {
  $vars['env']->addInclude('engine/code/php/core/search/css/search.css');
}