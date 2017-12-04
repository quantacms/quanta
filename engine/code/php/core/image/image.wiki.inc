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
  return '<a class="thumbnail" href="/' . $name. '">' . $image->render() . '</a>';
}

/**
 * Create a thumbnail / edited version of an image on the fly.
 * @param $attributes
 * @param $file
 * @param $string
 * @param $env
 * @return string
 */
function wiki_IMGTHUMB($attributes, $file, $string, $env) {
  $node = isset($attributes['node']) ? NodeFactory::load($env, $attributes['node']) : NodeFactory::current($env);
  $image = new Image($env, $file, $node);
  $image->loadAttributes($attributes);
  $tooltip = isset($attributes['tooltip']) ? (' tooltip="' . $attributes['tooltip'] . '"') : NULL;

  $op = isset($attributes['operation']) ? $attributes['operation'] : 'scale';
  $thumbfile = 'thumb-' . str_replace(' ', '-', str_replace('/', '-', $node->getName() . '-' . $image->width . 'x' . $image->height . '-'. $file));
  preg_replace("/[^A-Za-z0-9 ]/", '', $thumbfile);

	$filearr = explode('/', $file);
	$filename = $filearr[count($filearr) - 1];
  $image->generateThumbnail($env, $thumbfile, $image->width, $image->height, $op);

  // TODO: stupid way to get to the tmp folder...
  $src = '/thumbs/' . $thumbfile;

  if (isset($attributes['url'])) {
    $thumb = $src;
  }
  else {
    $thumb = '<img alt="' . $filename . '" ' . $tooltip . 'src="' . $src . '" />';
  }

  if (isset($attributes['link'])) {
    $link = empty($attributes['link']) ? ($node->name . '/' . $file) : $attributes['link'];
    $thumb = '<a href="/' . $link . '">' . $thumb . '</a>';
  }

  return $thumb;
}

function wiki_IMGTHUMBURL($attributes, $file, $string, $env) {
  $attributes['url'] = TRUE;
  return wiki_IMGTHUMB($attributes, $file, $string, $env);
}