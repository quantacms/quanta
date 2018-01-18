<?php
/**
 * @param $attributes
 * @param $var
 * @param $string
 * @param $env
 * @return mixed
 */
function wiki_PERMISSION($attributes, $var, $string, $env) {
  $node = empty($name) ? NodeFactory::current($env) : NodeFactory::load($env, $name);
  $permission = $attributes['name'];
  return $node->getPermission($permission);
}

/**
 * @param $attributes
 * @param $var
 * @param $string
 * @param $env
 * @return mixed
 */
function wiki_JSONPERMISSION($attributes, $var, $string, $env) {
  $node = empty($name) ? NodeFactory::current($env) : NodeFactory::load($env, $name);
  $permission = $attributes['name'];
  return $node->getPermission($permission);
}
