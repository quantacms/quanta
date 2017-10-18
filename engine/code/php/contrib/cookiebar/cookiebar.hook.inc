<?php
/*
 * Implements hook_init().
 */
function cookiebar_init($vars) {
  $vars['env']->addInclude('engine/code/php/contrib/cookiebar/css/jquery.cookiebar.css');
  $vars['env']->addInclude('engine/code/php/contrib/cookiebar/js/jquery.cookiebar.js');
}