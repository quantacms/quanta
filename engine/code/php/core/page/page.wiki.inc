<?php
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 08/05/15
 * Time: 12:20
 */

/**
 * Wiki Hook for PAGE.
 * TODO: to completely refactor. Hook. And fix as box doens't work.
 */
function wiki_PAGE($attributes, $var, $string, $page) {
  if ($var == 'actions') {
    return '<span class="logged-only"><b>admin:</b> <a class="open-manager">Gestione</a>&nbsp;&nbsp;<a class="add-link" href="#">&nbsp;&#9997; Nuovo...</a>&nbsp;&nbsp;<a class="edit-link" href="#">&#9999; Modifica</a>&nbsp;&nbsp;<a class="delete-link" href="#">&#10008; Elimina</a></span>';
  }
  elseif (isset($page->vars[$var])) {
    return $page->vars[$var];
  }
  else {
    return '?';
  }
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