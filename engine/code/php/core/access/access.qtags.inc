<?php
/**
 * Implements qtag PERMISSION.
 */
function qtag_PERMISSION($env, $target, $attributes) {
  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  $permission = $attributes['name'];
  return $node->getPermission($permission);
}
