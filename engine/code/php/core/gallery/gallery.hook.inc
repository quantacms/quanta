<?php
/**
 * Implements hook_load_includes().
 *
 * @param $vars
 *   Miscellaneous environment / page variables.
 */
function gallery_load_includes($vars) {
  $vars['env']->addInclude('engine/code/php/core/gallery/js/gallery.js');
  $vars['env']->addInclude('engine/code/php/core/gallery/css/gallery.css');
}
