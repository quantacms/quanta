<?php
/**
 * Wiki Hook for IMG.
 */
function wiki_IMG($attributes, $file, $string, $env) {
  $node =  NodeFactory::current($env);
  $image = new Image($env, $file, $node);
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
  $node = isset($attributes['node']) ? NodeFactory::load($env, $attributes['node']) : NodeFactory::current($env);
  $image = new Image($env, $file, $node);
  $image->loadAttributes($attributes);

  $op = isset($attributes['operation']) ? $attributes['operation'] : 'scale';
  $thumbfile = 'thumb-' . str_replace('/', '-', $node->getName() . '-' . $image->width . 'x' . $image->height . '-'. $file->name);

  generateThumbnail($env, $node->realpath . '/' . $file->name, $thumbfile, $image->width, $image->height, $op);

  // TODO: stupid way to get to the tmp folder...

  $thumb = '<img src="' . '/engine/tmp/' . $env->host . '/thumbs/' . $thumbfile . '" />';

  if (isset($attributes['link'])) {
    $link = empty($attributes['link']) ? ($node->name . '/' . $file) : $attributes['link'];
    $thumb = '<a href="/' . $link . '">' . $thumb . '</a>';
  }

  return $thumb;
}
