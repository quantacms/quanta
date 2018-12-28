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
function fontawesome_page_init(Environment $env, array $vars) {
  /** @var Page $page */
  //$page = $vars['page'];

  // TODO: find a better way to include fontawesome css.
  //$page->addCSS('https://maxcdn.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css');
}
