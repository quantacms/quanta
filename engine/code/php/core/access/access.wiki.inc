<?php
/**
 * @return mixed
 */
function wiki_PERMISSION($attributes, $var, $string, $env) {
  $node = empty($name) ? NodeFactory::current($env) : NodeFactory::load($env, $name);
  $permission = $attributes['name'];
  return $node->getPermission($permission);
}

/**
 * @return mixed
 */
function wiki_JSONPERMISSION($attributes, $var, $string, $env) {
  $node = empty($name) ? NodeFactory::current($env) : NodeFactory::load($env, $name);
  $permission = $attributes['name'];
  return $node->getPermission($permission);
}
