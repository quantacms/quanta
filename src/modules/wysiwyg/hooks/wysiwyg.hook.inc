<?php
namespace Quanta\Common;

/**
 * Implements hook_page_init().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function wysiwyg_page_init(Environment $env, array $vars) {

  if (TRUE || Shadow::isOpen($env)) {
    $attributes = array('external');
    print new \Quanta\Qtags\Css($env, $attributes, $env->dir['vendor'] . '/xdan/jodit/build/jodit.min.css');
    $attributes = array('inline' => TRUE);
    print new \Quanta\Qtags\Js($env, $attributes, $env->dir['vendor'] . '/xdan/jodit/build/jodit.min.js');
    print new \Quanta\Qtags\Js($env, $attributes, $env->getModulePath('wysiwyg') . '/assets/js/wysiwyg.js');

  }
}
