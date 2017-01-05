<?php
/**
 * Wiki Hook for IMG.
 */
function wiki_IMG($attributes, $var, $string, $env) {
  $node =  NodeFactory::current($env);
  $image = new Image($env, $var, $node);
  $image->loadAttributes($attributes);
  return $image->render();
}

// TODO: deprecate
function wiki_THUMBNAIL($attributes, $name, $string, $env) {
  $node = empty($name) ? NodeFactory::current($env) : NodeFactory::load($env, $name);
  $image = new Image($env, '/' . $node->getName() . '/' . $node->getThumbnail(), $node);
  $image->loadAttributes($attributes);
  return '<a href="/' . $name. '">' . $image->render() . '</a>';
}

function wiki_IMGTHUMB($attributes, $file, $string, $env) {
  $node = NodeFactory::load($env, $attributes['node']);
  $thumbfile = 'thumb-' . str_replace('/', '-', $node->getName() . '-' . $file);
  generateThumbnail($env, $node->path . '/' . $file, $thumbfile, 600, 250);
  // TODO: stupid way to get to the tmp folder...
  return '<img src="' . '/engine/tmp/' . $env->host . '/thumbs/' . $thumbfile . '" />';
}
