<?php
/**
 * Implements qtag CSS.
 *
 * Automatically generated css data.
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
function qtag_CSS($env, $target, $attributes) {
  $page = $env->getData('page');


  // If target is specified, include the css file directly.
  if (isset($target)) {
    if (isset($attributes['module'])) {
      $target = $env->getModule($attributes['module'])['path'] . '/' . $target;
    }
    $css = array($target);
  }

  else {
    // If no target specified, assume loading of all page includes.
    $css = $page->getData('css');
    $inline_css = $page->getData('css_inline');
  }

  if (empty($attributes['external'])) {
    $css_code = '<style>';
    // TODO: converting all inclusions into inline stylesheets. Faster, but is it good?
    foreach ($css as $css_file) {
      $css_code .= file_get_contents($css_file);
    }

    if (!empty($inline_css)) {
      foreach ($inline_css as $inline_css_code) {
        $css_code .= $inline_css_code . "\n";
      }
    }
    $css_code .= '</style>';
  }
  else {
    $css_code = '<link rel="stylesheet" href="' . $target . '" type="text/css" />';
  }
  return $css_code;
}

/**
 * Implements qtag JS.
 *
 * Automatically generated js data.
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
function qtag_JS($env, $target, $attributes) {
  $page = $env->getData('page');

  $js_code = '';
  $refresh =  isset($attributes['refresh']) ? ('?' . Doctor::timestamp($env)) : '';

  // If target is specified, include the css file directly.
  if (isset($target)) {
    if (isset($attributes['module'])) {
      $target = $env->getModule($attributes['module'])['path'] . '/' . $target;
    }
    else {
      $target = $env->dir['docroot'] . '/' . $target;
    }
    $js = array($target);
  }
  else {
    // If no target specified, assume loading of all page includes.
    $js = $page->getData('js');
  }

  // TODO: converting all inclusions into inline stylesheets. Faster, but is it good?
  foreach ($js as $js_file) {
		$async = !empty($attributes['async']) ? ' async ' : '';
    // TODO: support per file async.
		if (isset($attributes['inline'])) {
			$js_code .= '<script>' . file_get_contents($js_file) . '</script>';
    }
    else {
      $js_code .= '<script src="' . $js_file . $refresh . '"></script>';
    }
  }

  return $js_code;
}

/**
 * Implements qtag BODYCLASSES.
 *
 * Automatically generated header data.
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
function qtag_BODYCLASSES($env, $target, $attributes) {
  $page = $env->getData('page');
  $body_classes = $page->getData('body_classes');
  return implode(' ', $body_classes);
}

/**
 * Implements qtag CONTEXT.
 *
 * Renders the current context.
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
function qtag_CONTEXT($env, $target, $attributes) {
  return $_REQUEST['context'];
}


/**
 * Implements qtag EMPTY.
 *
 * Render an empty content (useful as ajax container).
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
function qtag_EMPTY($env, $target, $attributes) {
  $text = isset($attributes['text']) ? $attributes['text'] : '&nbsp;';
  return $text;
}

/**
 * Implements qtag Breadcrumb.
 *
 * Renders the full breadcrumb / lineage of the current node.
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
function qtag_BREADCRUMB($env, $target, $attributes) {
  $node = NodeFactory::current($env);

  // Check if current node id home (main node).
  if ($node->getName() == 'home'){
    // Do not show breadcrumb.
    return;
  }

  $node_home = NodeFactory::load($env, 'home');
  // Builds the lineage of the node.
  $node->buildLineage();
  // Starts with home node.
  $breadcrumb = array('home' => $node_home) + $node->getLineage();

  if (empty($attributes['include_current'])) {
    array_pop($breadcrumb);
  }

  // TODO: breadcrumb generation must be done in page.class.

  $env->setData('breadcrumb', $breadcrumb);
  $themed_bc = '';
  // Theme and renders the breadcrumb.
  $themed_bc = '<ul class="breadcrumb">';
  if (count($breadcrumb) > 0 && $breadcrumb != '') {
    foreach ($breadcrumb as $i => $node) {
      // Add only published nodes without "breadcrumb_exclude".
      if ($node->isPublished() && !$node->getAttributeJSON('breadcrumb_exclude')) {
        $themed_bc .= '<li>[LINK:'. $node->getName() . ']</li>';
      }
    }
  }
  $themed_bc .= '</ul>';
  return $themed_bc;
}

/**
 * Implements qtag JSON.
 *
 * Encodes the target into json.
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
function qtag_JSON($env, $target, $attributes) {
  return json_encode($target);
}
