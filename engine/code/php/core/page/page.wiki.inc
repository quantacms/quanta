<?php
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 08/05/15
 * Time: 12:20
 */

/**
 * Wiki Hook for PAGE.
 */
function wiki_PAGE($replace, $attributes, $var, $string, $page) {
  $string = str_replace($replace, $page->vars[$var], $string);
  return $string;
}


/**
 * Wiki Hook for BREADCRUMB.
 */
function wiki_BREADCRUMB($replace, $attributes, $var, $string, $page) {
  $themed_bc = '';
  $breadcrumb = $page->env->getData('breadcrumb');
  if (count($breadcrumb) > 0 && $breadcrumb != '') {
    $themed_bc = '<div class="breadcrumb-block">' . (isset($attributes['label']) ? $attributes['label'] : 'You are in:') . '<ul class="breadcrumb"><li>[home:Home]</li>';
      foreach ($breadcrumb as $path => $title) {
        $themed_bc .= '<li>[' . $path . ':' . $title . ']</li>';
      }
    $themed_bc .= '</ul></div>';
  }

  $string = str_replace($replace, $themed_bc, $string);
  return $string;
}