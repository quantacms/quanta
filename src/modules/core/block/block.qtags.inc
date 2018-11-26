<?php
namespace Quanta\Common;

/**
 * Implements qtag BLOCK.
 *
 * Renders a node as a block that can be embedded elsewhere.
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
function qtag_BLOCK($env, $target, &$attributes) {
  $node = NodeFactory::load($env, $target);

  if (isset($attributes['rendered'])) {
    $body = NodeFactory::render($env, $target);
  }
  else {
    $node = NodeFactory::load($env, $target);
    $body = isset($attributes['with-title']) ? ('<h2 class="block-title">' . filter_xss($node->getTitle()) . '</h2>' . $node->getBody()) : $node->getBody();
  }

  // Wrap in the inline editor.
  if (empty($attributes['editable']) || $attributes['editable'] == 'true') {
    $body = NodeTemplate::wrap($env, $node, $body);
  }
  // If user can't see the node, don't display it.
  return $node->isForbidden() ? '' : $body;
}


/**
 * Implements qtag WRAPPER.
 *
 * Renders a wrapper around the target content.
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
function qtag_WRAPPER($env, $target, &$attributes) {
  $wrapper_html_tag = !empty($attributes['wrapper_html_tag']) ? $attributes['wrapper_html_tag'] : 'div';
  $wrapper_id = !empty($attributes['wrapper_id']) ? $attributes['wrapper_id'] : '';
  $wrapper_class = !empty($attributes['wrapper_class']) ? $attributes['wrapper_class'] : '';

  return  '<' . $wrapper_html_tag . (!empty($wrapper_id) ? ' id="' . $wrapper_id . '"' : '') . (!empty($wrapper_class) ? ' class="' . $wrapper_class . '"' : '') . '>' . $target . '</' . $wrapper_html_tag . '>';
}