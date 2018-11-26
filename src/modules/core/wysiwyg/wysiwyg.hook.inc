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
function wysiwyg_page_init($env, $vars) {
  /** @var Page $page */
  $page = $vars['page'];

  if (Shadow::isOpen($env)) {
    // Include the codemirror lib.
    $page->addCSS('https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.3.0/codemirror.min.css');
  }
}
