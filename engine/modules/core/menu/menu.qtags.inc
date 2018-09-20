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

  if (isset($attributes['links'])){
    $links = explode('---', $attributes['links']);
    $links_html = array();
    $menu_class = isset($attributes['menu_class']) ? $attributes['menu_class'] : '';

    foreach ($links as $link) {
      $links_html[] = '<li>' . $link . '</li>';
    }

    return '<nav' . (!empty($menu_class) ? ' class="' . $menu_class . '"' : '') . '><ul>' . implode($links_html) . '</ul></nav>';
  }

}
