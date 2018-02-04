<?php
/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param $vars
 *   An array of variables.
 */
function cookiebar_load_includes($env, $vars) {
  $env->addInclude('engine/code/php/contrib/cookiebar/css/jquery.cookiebar.css');
  $env->addInclude('engine/code/php/contrib/cookiebar/js/jquery.cookiebar.js');
}
