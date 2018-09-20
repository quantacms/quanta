<?php
/**
 * Implements QUANTA qtag.
 *
 * Renders a "Powered by Quanta" link. Support is appreciated!...
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
function qtag_QUANTA($env, $target, $attributes) {

	return '<span class="powered-by-quanta">Powered by <a href="https://www.quantacms.com">Quanta CMS</a></span>';
}
/**
 * Implements RANDOM qtag.
 *
 * Renders a random number.
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
function qtag_RANDOM($env, $target, $attributes) {
  $min = isset($attributes['min']) ? $attributes['min'] : 0;
  $max = isset($attributes['max']) ? $attributes['max'] : 1000000;
  return rand($min, $max);
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
  if (empty($target)) {
    $target = $env->getRequestedPath();
  }
  return $env->getBaseUrl() . '/' . $target;
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
 * Implements qtag BACK.
 *
 * Renders a "back" button link.
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
 *   The back button.
 */
function qtag_BACK($env, $target, $attributes) {
  $title = isset($attributes['title']) ? $attributes['title'] : 'Back';
  return '<a href="#" onclick="history.back()">' . $title . '</a>';
}

/**
 * Implements qtag BACK.
 *
 * Renders a "back" button link.
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
 *   The back button.
 */
function qtag_NODE_JSON($env, $target, $attributes) {
  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  $fields = empty($attributes['fields']) ? array('name', 'teaser', 'author', 'body') : explode(',', $attributes['fields']);
  $values = array();
  foreach ($fields as $field) {
    $values[] = '"' . $field . '"' . ':"' . $node->getAttributeJSON($field) . '"';
  }
  $string = "{\n" . implode(",\n", $values) . "\n}";
  return $string;
}
