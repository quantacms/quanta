<?php
/**
 * Wiki Hook for IMG.
 */
function wiki_IMG($attributes, $var, $string, $page) {
  $node = ($page->env->getData('node'));
  $image = new Image($page->env, $var, $node);
  $image->loadAttributes($attributes);
  return $image->render();
}

function wiki_THUMB($attributes, $var, $string, $page) {
  $attributes[] = 'class=thumb';
  return wiki_IMG($attributes, $var, $string, $page);
}

function wiki_THUMBNAIL($attributes, $name, $string, $page) {
  $node = empty($name) ? $page->env->getData('node') : new Node($page->env, $name);

  $image = new Image($page->env, $node->getThumbnail(), $node);

  return $image->render();
}
