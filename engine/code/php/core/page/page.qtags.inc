<?php
/**
 * Implements qtag HEAD.
 *
 * Automatically generated header data.
 * TODO: discontinue. Use JS and CSS instead.
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
function qtag_HEAD($env, $target, $attributes) {
  return qtag_CSS($env, $target, $attributes) . qtag_JS($env, $target, $attributes);
}

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
  $css = $page->getData('css');
  return implode('
  ', $css);
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
  $js = $page->getData('js');
  return implode('
  ', $js);
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
 * Implements qtag URL.
 *
 * Renders the current site URL.
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
function qtag_URL($env, $target, $attributes) {
  return $env->getBaseUrl() . '/' . $env->getRequestedPath();
}

/**
 * Implements qtag STRING.
 *
 * Renders a simple text / string.
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
function qtag_STRING($env, $target, $attributes) {
  return $target;
}

/**
 * Implements qtag DATE.
 *
 * Renders a formatted date.
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
function qtag_DATE($env, $target, $attributes) {
  $date = new DateTime($target);
  if (isset($attributes['format'])) {
    $formatted_date = $date->format($attributes['format']);
  }
  else {
    $formatted_date = $target;
  }
  return $formatted_date;
}

/**
 * Implements qtag EMAIL.
 *
 * Renders a clickable email address.
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
function qtag_EMAIL($env, $target, $attributes) {
  $string = '';
  if (valid_email($target)) {
    $string = '<a class="mail" href="mailto:' . $target . '">' . (isset($attributes['title']) ? $attributes['title'] : $target)  . '</a>';
  }
  return $string;
}

/**
 * Implements qtag PHONE.
 *
 * Renders a phone number.
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
function qtag_PHONE($env, $target, $attributes) {
  if (isset($attributes['class'])){
    $attributes['class'] .= ' tel';
  }
  else {
    $attributes['class'] = ' tel';
  }
  
	return '<a class="phone" href="tel:' . $target . '">' . $target . '</a>';
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
