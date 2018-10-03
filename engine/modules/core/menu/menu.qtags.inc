<?php
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
function qtag_MENU($env, $target, $attributes) {
  $links_html = array();
  $menu_class = isset($attributes['menu_class']) ? $attributes['menu_class'] : '';
  $menu_list_class = isset($attributes['menu_list_class']) ? $attributes['menu_list_class'] : '';

  if (isset($attributes['links'])) {
    // Get links directly.
    $links = explode('---', $attributes['links']);
  } elseif(!empty($target)) {
    // Get links from target node.
    $node = NodeFactory::load($env, $target);
    $node->buildTemplate();
    $links = explode("\n", trim($node->render()));
  }

  foreach ($links as $link) {
    $links_html[] = '<li>' . $link . '</li>';
  }

  return '<nav' . (!empty($menu_class) ? ' class="' . $menu_class . '"' : '') . '><ul' . (!empty($menu_list_class) ? ' class="' . $menu_list_class . '"' : '') . '>' . implode($links_html) . '</ul></nav>';

}
