<?php
/*
 * Implements hook_init().
 */
function slider_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/slider/js/slider.js');
  $vars['env']->addInclude('engine/code/php/core/slider/css/slider.css');
}
