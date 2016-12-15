<?php
/**
 * Wiki Hook for IMG.
 */
function wiki_IMG($attributes, $var, $string, $env) {
  $node = ($env->getData('node'));
  $image = new Image($env, $var, $node);
  $image->loadAttributes($attributes);
  return $image->render();
}

// TODO: deprecate
function wiki_THUMB($attributes, $var, $string, $page) {
  $attributes[] = 'class=thumb';
  return wiki_IMG($attributes, $var, $string, $page);
}

// TODO: deprecate
function wiki_THUMBNAIL($attributes, $name, $string, $env) {
  $node = empty($name) ? $env->getData('node') : new Node($env, $name);
  $image = new Image($env, $node->getThumbnail(), $node);
  $image->loadAttributes($attributes);
  return '<a href="/' . $name. '">' . $image->render() . '</a>';
}

function wiki_IMGTHUMB($attributes, $file, $string, $env) {
  $attributes[] = 'class=thumb';
  $thumbfile = 'thumb-' . str_replace('/', '-', $file);

  generateThumbnail($env, $file, $thumbfile, 600, 250);

  return '<img src="http://mixbag/engine/tmp/mixbag/thumbs/' . $thumbfile . '" />';
}