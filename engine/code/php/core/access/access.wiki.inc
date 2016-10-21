<?php
/**
 * @return mixed
 */
function wiki_PERMISSION($attributes, $var, $string, $env) {
  $node = empty($name) ? $env->getData('node') : new Node($env, $name);
  $permission = $attributes['name'];
  return $node->getPermission($permission);
}
