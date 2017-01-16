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
 * Wiki Hook for URL.
 */
function wiki_URL($attributes, $target, $string, $env) {
  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
	return $_SERVER['HTTP_HOST'] . '/' . $node->getName();
}

/**
 * Wiki Hook for URL.
 */
function wiki_MAIL($attributes, $mail, $string, $env) {
  return '<a href="mailto:' . $mail . '">' . (isset($attributes['title']) ? $attributes['title'] : $mail)  . '</a>';
}

/**
 * Wiki Hook for URL.
 */
function wiki_PHONE($attributes, $phone, $string, $env) {
	return '<span class="phone-number">' . $phone . '</span>';
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
  $node = NodeFactory::current($env);
  $breadcrumb = array();
  $node->buildLineage();
  foreach ($node->getLineage() as $n) {
    $breadcrumb[$n->getName()] = $n;
  }

  $env->setData('breadcrumb', $breadcrumb);
  $themed_bc = '';
  if (count($breadcrumb) > 0 && $breadcrumb != '') {
    $themed_bc = '<ul class="breadcrumb">';
    $themed_bc .= '<li>[LINK|title=Home:home]</li>';
      foreach ($breadcrumb as $path => $node) {
        if ($node->isPublished()) {
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
