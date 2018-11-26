<?php
namespace Quanta\Common;

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function box_load_includes($env, $vars) {
  $module_path = $env->getModulePath('box');

  $env->addInclude($module_path . '/css/box.css');
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
  $qtag = $vars['qtag'];

  if (!empty($qtag->getData('box'))) {
    $boxsize = explode('x', $vars['qtag']->getData('box'));
    $w = empty($boxsize[1]) ? NULL : $boxsize[0];
    $h = empty($boxsize[1]) ? NULL : $boxsize[1];
    $box = new Box($env, $w, $h, $vars['qtag']->getAttributes());
    $box->setHtml($vars['qtag']->html);
    $vars['qtag']->html = $box->render();
  }
}
