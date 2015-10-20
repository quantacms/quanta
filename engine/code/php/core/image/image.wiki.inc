<?php
/**
 * Wiki Hook for IMG.
 */
function wiki_IMG($replace, $attributes, $var, $string, $page) {
  $node = ($page->env->getData('node'));

  $image = new Image($page->env, $var, $node);
  $image->loadAttributes($attributes);
  $string = str_replace($replace, $image->render(), $string);
  return $string;
}

function wiki_THUMB($replace, $attributes, $var, $string, $page) {
  $attributes[] = 'class=thumb';
  return wiki_IMG($replace, $attributes, $var, $string, $page);
}