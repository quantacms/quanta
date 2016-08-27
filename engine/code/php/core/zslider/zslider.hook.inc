<?php
/*
 * Implements hook_init().
 */
function zslider_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/zslider/js/slider.js');
  $vars['env']->addInclude('engine/code/php/core/zslider/css/slider.css');
}
