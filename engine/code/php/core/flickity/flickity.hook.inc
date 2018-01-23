<?php
/**
 * Implements hook_load_includes().
 *
 * @param $vars
 *   Miscellaneous environment / page variables.
 */
function flickity_load_includes($vars) {
  $vars['env']->addInclude('engine/code/php/core/flickity/js/flickity.pkgd.min.js');
  $vars['env']->addInclude('engine/code/php/core/flickity/css/flickity.css');

}
