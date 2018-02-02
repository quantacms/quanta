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
function flickity_load_includes($env, $vars) {
  $env->addInclude('engine/code/php/core/flickity/js/flickity.pkgd.min.js');
  $env->addInclude('engine/code/php/core/flickity/css/flickity.css');

}
