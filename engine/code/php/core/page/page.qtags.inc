<?php
/**
 * Implements qtag HEAD.
 *
 * Automatically generated header data.
 * TODO: discontinue. Use JS and CSS instead.
 */
function qtag_HEAD($env, $target, $attributes) {
  return qtag_CSS($env, $target, $attributes) . qtag_JS($env, $target, $attributes);
}

/**
 * Implements qtag CSS.
 *
 * Automatically generated css data.
 */
function qtag_CSS($env, $target, $attributes) {
  $page = $env->getData('page');
  $css = $page->getData('css');
  return implode('
  ', $css);
}

/**
 * Implements qtag JS.
 *
 * Automatically generated js data.
 */
function qtag_JS($env, $target, $attributes) {
  $page = $env->getData('page');
  $js = $page->getData('js');
  return implode('
  ', $js);
}

/**
 * Implements qtag META_DATA.
 *
 * Automatically generated page meta data.
 */
function qtag_META_DATA($env, $target, $attributes) {
  $page = $env->getData('page');
  $meta = $page->getData('metadata');
  return implode('
  ', $meta);
}

/**
 * Implements qtag BODYCLASSES.
 *
 * Automatically generated header data.
 */
function qtag_BODYCLASSES($env, $target, $attributes) {
  $page = $env->getData('page');
  $body_classes = $page->getData('body_classes');
  return implode(' ', $body_classes);
}

/**
 * Implements qtag URL.
 *
 * Renders the current site URL.
 */
function qtag_URL($env, $target, $attributes) {
	return 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
}

/**
 * Implements qtag EMAIL.
 *
 * Renders a clickable email address.
 */
function qtag_EMAIL($env, $target, $attributes) {
  if (valid_email($target)) {
    return '<a class="mail" href="mailto:' . $target . '">' . (isset($attributes['title']) ? $attributes['title'] : $target)  . '</a>';
  }
}

/**
 * Implements qtag PHONE.
 *
 * Renders a phone number.
 */
function qtag_PHONE($env, $target, $attributes) {
  if (isset($attributes['class'])){
    $attributes['class'] .= ' tel';
  } else {
    $attributes['class'] = ' tel';
  }
  
	return '<a ' . html_standard_attributes($attributes) . ' href="tel:' . $target . '">' . $phone . '</a>';
}

/**
 * Implements qtag CONTEXT.
 *
 * Renders the current context.
 *
 */
function qtag_CONTEXT($env, $target, $attributes) {
  return $_REQUEST['context'];
}


/**
 * Implements qtag EMPTY.
 *
 * Render an empty content (useful as ajax container).
 *
 */
function qtag_EMPTY($env, $target, $attributes) {
  $text = isset($attributes['text']) ? $attributes['text'] : '&nbsp;';
  return $text;
}

/**
 * Implements qtag Breadcrumb.
 *
 * Renders the full breadcrumb / lineage of the current node.
 */
function qtag_BREADCRUMB($env, $target, $attributes) {
  $node = NodeFactory::current($env);
  $breadcrumb = array();
  // Builds the lineage of the node.
  $node->buildLineage();
  foreach ($node->getLineage() as $n) {
    $breadcrumb[$n->getName()] = $n;
  }

  // TODO: breadcrumb generation must be done in page.

  $env->setData('breadcrumb', $breadcrumb);
  $themed_bc = '';
  // Theme and renders the breadcrumb.
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

