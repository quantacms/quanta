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
  }
}
