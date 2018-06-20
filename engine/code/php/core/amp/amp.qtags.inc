<?php
/**
 * Implements AMP_LINK qtag.
 *
 * Render a link to the amp version of the node.
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
function qtag_AMP_LINK($env, $target, $attributes) {
  $node = NodeFactory::current($env);
  return '<link rel="amphtml" href="' . ($env->getBaseUrl() . '/amp/' . $node->getName()) . '">';

}


/**
 * Implements CANONICAL_LINK qtag.
 *
 * Render a link to the amp version of the node.
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
function qtag_CANONICAL_LINK($env, $target, $attributes) {
  $node = NodeFactory::current($env);
  return '<link rel="canonical" href="' . ($env->getBaseUrl() . '/' . $node->getName()) . '">';

}
