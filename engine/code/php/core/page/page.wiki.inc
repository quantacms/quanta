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
function wiki_PAGE($attributes, $var, $string, $page) {
  return $page->vars[$var];
}


/**
 * Wiki Hook for BREADCRUMB.
 */
function wiki_BREADCRUMB($attributes, $var, $string, $page) {
  $themed_bc = '';
  $breadcrumb = $page->env->getData('breadcrumb');
  if (count($breadcrumb) > 0 && $breadcrumb != '') {
    $themed_bc = '<div class="breadcrumb-block">' . (isset($attributes['label']) ? $attributes['label'] : 'You are in:') . '<ul class="breadcrumb"><li>[home:Home]</li>';
      foreach ($breadcrumb as $path => $title) {
        if ($path == '_users') {
        $themed_bc .= '<li>Users</li>';
        }
        else {
          $themed_bc .= '<li>[' . $path . ':' . $title . ']</li>';
        }
      }
    $themed_bc .= '</ul></div>';
  }

  return $themed_bc;
}