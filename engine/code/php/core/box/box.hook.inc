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
  $env->addInclude('engine/code/php/core/box/js/box.js');
  $env->addInclude('engine/code/php/core/box/css/box.css');
}

/**
 * Render a box.
 * TODO: deprecate in favor of qtag.
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function box_wikitag($env, $vars) {
  if (!empty($vars['attributes']['box'])) {
    $boxsize = explode('x', $vars['attributes']['box']);
    $w = empty($boxsize[1]) ? NULL : $boxsize[0];
    $h = empty($boxsize[1]) ? NULL : $boxsize[1];
    $box = new Box($env, $w, $h, $vars['attributes']);

    foreach ($vars['wikitag'] as $tag => $content) {
      $box->setHtml($content);
      $vars['wikitag'][$tag] = $box->render();
    }
  }
}

/**
 * Render a Quanta box.
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

    foreach ($vars['qtag'] as $tag => $content) {
      $box->setHtml($content);
      $vars['qtag'][$tag] = $box->render();
    }
  }
}
