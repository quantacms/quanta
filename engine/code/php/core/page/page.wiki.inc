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
function wiki_PAGE($attributes, $var, $string, $env) {
  $page = $env->getData('page');
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
 * Wiki Hook for CONTEXT.
 */
function wiki_CONTEXT($attributes, $var, $string, $env) {
  return $_REQUEST['context'];
}


/**
 * Render an empty content (useful as ajax container).
 * Wiki Hook for EMPTY.
 */
function wiki_EMPTY($attributes, $var, $string, $env) {
  $text = isset($attributes['text']) ? $attributes['text'] : '&nbsp;';
  return $text;
}

/**
 * Wiki Hook for BREADCRUMB.
 */
function wiki_BREADCRUMB($attributes, $var, $string, $env) {

  $themed_bc = '';
  $breadcrumb = $env->getData('breadcrumb');

  if (count($breadcrumb) > 0 && $breadcrumb != '') {
    $themed_bc = '<ul class="breadcrumb">';
    $themed_bc .= '<li>[LINK|title=Home:home]</li>';
      foreach ($breadcrumb as $path => $title) {
        if ($path == '_users') {
        $themed_bc .= '<li>Users</li>';
        }
        else {
          $themed_bc .= '<li>[LINK:'. $path . ']</li>';
        }
      }
    $themed_bc .= '</ul>';
  }

  return $themed_bc;
}

/**
 * Wiki Hook for EMAIL.
 */
function wiki_EMAIL($attributes, $var, $string, $env) {
  return '<a href="mailto:' . $var. '">' . $var . '</a>';

}