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
    $attributes = array('external');
    print new \Quanta\Qtags\Css($env, $attributes, 'https://cdnjs.cloudflare.com/ajax/libs/jodit/3.1.39/jodit.min.css');
    print new \Quanta\Qtags\Js($env, $attributes, 'https://cdnjs.cloudflare.com/ajax/libs/jodit/3.1.39/jodit.min.js');
    $attributes = array('inline' => TRUE, 'module' => 'wysiwyg');
    print new \Quanta\Qtags\Js($env, $attributes, '/assets/js/wysiwyg.js');

  }
}
