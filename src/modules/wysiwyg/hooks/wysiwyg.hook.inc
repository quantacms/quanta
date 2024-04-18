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
  if (Shadow::isOpen($env)) {
    $attributes = array('file_inline' => TRUE);
    print new \Quanta\Qtags\Js($env, $attributes, $env->getModulePath('wysiwyg') . '/assets/js/ckeditor.js');
    print new \Quanta\Qtags\Js($env, $attributes, $env->getModulePath('wysiwyg') . '/assets/js/wysiwyg.js');
  }
}
