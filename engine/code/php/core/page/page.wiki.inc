<?php
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 08/05/15
 * Time: 12:20
 */

/**
 * Wiki Hook for HEAD DATA.
 * Automatically generated header data.
 */
function wiki_HEAD($attributes, $var, $string, $env) {
  $page = $env->getData('page');
  $head = $page->getData('head');
  return implode('
  ', $head);
}


/**
 * Wiki Hook for METADATA.
 * Automatically generated meta data.
 */
function wiki_META_DATA($attributes, $var, $string, $env) {

  $page = $env->getData('page');
  $meta = $page->getData('metadata');
  return implode('
  ', $meta);
}

/**
 * Wiki Hook for HEAD DATA.
 * Automatically generated header data.
 */
function wiki_BODYCLASSES($attributes, $var, $string, $env) {
  $page = $env->getData('page');
  $body_classes = $page->getData('body_classes');
  return implode(' ', $body_classes);
}

/**
 * Wiki Hook for URL.
 */
function wiki_URL($attributes, $var, $string, $env) {
	return 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
}

/**
 * Wiki Hook for MAIL.
 */
function wiki_MAIL($attributes, $mail, $string, $env) {
  return '<a class="mail" href="mailto:' . $mail . '">' . (isset($attributes['title']) ? $attributes['title'] : $mail)  . '</a>';
}

/**
 * Wiki Hook for PHONE.
 */
function wiki_PHONE($attributes, $phone, $string, $env) {
	return '<a class="tel" href="tel:' . $phone . '">' . $phone . '</a>';
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
