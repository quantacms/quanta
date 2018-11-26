<?php
namespace Quanta\Common;

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
function qtag_PERMISSION($env, $qtag) {
  $node = empty($qtag->getTtarget) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  if ($node->isNew()) {
    $permission = 'inherit';
  }
  else {
    if (!empty($node->getAttributeJSON('permissions')->{$attributes['name']})) {
      // Try to fetch the permission from the node, first.
      $permission = $node->getAttributeJSON('permissions')->{$attributes['name']};
    }
    else {
      $permission = $node->getPermission($attributes['name']);
    }
  }
  return $permission;
}
