<?php

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function box_load_includes($env, $vars) {
  $env->addInclude('engine/code/php/core/box/css/box.css');
}

/**
 * Renders a Quanta box.
 *
 * @see Box.class.inc
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function box_qtag($env, $vars) {
  if (!empty($vars['attributes']['box'])) {
    $boxsize = explode('x', $vars['attributes']['box']);
    $w = empty($boxsize[1]) ? NULL : $boxsize[0];
    $h = empty($boxsize[1]) ? NULL : $boxsize[1];
    $box = new Box($env, $w, $h, $vars['attributes']);
    $box->setHtml($vars['qtag']);
    $vars['qtag'] = $box->render();
  }
}
