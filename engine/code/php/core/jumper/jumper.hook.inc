<?php
/**
 * Implements hook_load_includes().
 *
 * @param $vars
 *   Miscellaneous environment / page variables.
 */
function jumper_load_includes($vars) {
  $vars['env']->addInclude('engine/code/php/core/jumper/js/jumper.js');
}
