<?php
/**
 * Implements qtag PERMISSION.
 *
 * Show a permission of a node.
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
function qtag_PERMISSION($env, $target, $attributes) {
  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  if ($node->isNew()) {
    $permission = 'inherit';
  }
  else {
    if (!empty($node->getAttributeJSON('permissions')->{$attributes['name']})) {
      // Try to fetch the permission from the node, first.
      $permission = $node->getAttributeJSON('permissions')->{$attributes['name']};
    }
    else {
      $permission = $node->getPermission($attributes['name'], 'form');
    }
  }

  return $permission;
}
