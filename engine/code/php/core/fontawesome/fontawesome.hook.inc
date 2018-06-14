<?php
/**
 * Implements hook_metadata().
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 *
 */
function fontawesome_page_init($env, $vars) {
  /** @var Page $page */
  $page = $vars['page'];

  if (Shadow::isOpen($env)) {
    // Include the codemirror lib.
    $page->addCSS('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css');
  }
}
