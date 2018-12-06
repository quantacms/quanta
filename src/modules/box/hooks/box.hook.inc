<?php
namespace Quanta\Common;
use Quanta\Qtags\Quanta;

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function box_load_includes(Environment $env, array $vars) {
  $module_path = $env->getModulePath('box');

  $env->addInclude($module_path . '/assets/css/box.css');
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
function box_qtag(Environment $env, array $vars) {
  $qtag = $vars['qtag'];

  if (!empty($qtag->getAttribute('box'))) {
    $boxsize = explode('x', $vars['qtag']->getAttribute('box'));
    $w = empty($boxsize[1]) ? NULL : $boxsize[0];
    $h = empty($boxsize[1]) ? NULL : $boxsize[1];
    $box = new Box($env, $w, $h, $vars['qtag']->getAttributes());
    $box->setHtml($vars['qtag']->html);
    $vars['qtag']->html = $box->render();
  }
}
