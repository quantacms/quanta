<?php
/*
 * Implements hook_init().
 */
function flickity_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/flickity/js/flickity.pkgd.min.js');
  $vars['env']->addInclude('engine/code/php/core/flickity/css/flickity.css');

}
