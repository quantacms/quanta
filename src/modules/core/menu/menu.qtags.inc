<?php
namespace Quanta\Common;

/**
 * Implements MENU qtag.
 *
 * Renders menu links.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_MENU($env, $target, &$attributes) {
  $links = array();
  $links_html = array();

  $menu_id = isset($attributes['menu_id']) ? $attributes['menu_id'] : '';
  $menu_class = isset($attributes['menu_class']) ? $attributes['menu_class'] : '';
  $menu_list_class = isset($attributes['menu_list_class']) ? $attributes['menu_list_class'] : '';

  if (isset($attributes['links'])) {
    // Get links directly.
    $links = explode('---', $attributes['links']);
  }
  elseif(!empty($target)) {
    // Get links from target node.
    $rendered = NodeFactory::render($env, $target);
    $links = explode("\n", trim($rendered));
  }

  foreach ($links as $link) {
    $links_html[] = '<li class="menu-link-item">' . $link . '</li>';
  }

  // Return the full nav.
  return '<nav' . (!empty($menu_id) ? ' id="' . $menu_id . '"' : '') . (!empty($menu_class) ? ' class="' . $menu_class . '"' : '') . '><ul' . (!empty($menu_list_class) ? ' class="' . $menu_list_class . '"' : '') . '>' . implode($links_html) . '</ul></nav>';

}
