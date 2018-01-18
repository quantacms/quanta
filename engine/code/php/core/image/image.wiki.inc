<?php
/**
 * Wiki Hook for IMG.
 * @param $attributes
 * @param $file
 * @param $string
 * @param $env
 * @return string
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
  $attributes['link'] = $node->getName();
	$attributes['node'] = $node->getName();
	if (isset($_GET['test'])) print_r($attributes); 
		return wiki_IMGTHUMB($attributes, $node->getThumbnail(), $string, $env);

}

/**
 * Create a thumbnail / edited version of an image on the fly.
 *
 * @param $attributes
 * @param $file
 * @param $string
 * @param $env
 * @return string
 */
function wiki_IMGTHUMB($attributes, $file, $string, $env) {
  $node = isset($attributes['node']) ? NodeFactory::load($env, $attributes['node']) : NodeFactory::current($env);

  // Load the image.
  $image = new Image($env, $file, $node);
  $image->loadAttributes($attributes);

  // Setup tooltip.
  $tooltip = isset($attributes['tooltip']) ? (' data-tooltip="' . $attributes['tooltip'] . '"') : NULL;

  // Setup compression level (JPEG / PNG).
  $compression = isset($attributes['compression']) ? $attributes['compression'] : 60;

  // Setup alt for the image.
  $alt = isset($attributes['alt']) ? $attributes['alt'] : $file;


  // Setup title for the image link (if present).
  $alt = isset($attributes['link_title']) ? $attributes['link_title'] : NULL;

  // Setup operations to run on the image.
  $op = isset($attributes['operation']) ? $attributes['operation'] : 'scale';

  $thumbfile = 'thumb-' . str_replace(' ', '-', str_replace('/', '-', $node->getName() . '-' . $image->width . 'x' . $image->height . '-'. $file));
  preg_replace("/[^A-Za-z0-9 ]/", '', $thumbfile);
	$filearr = explode('/', $file);
	$filename = $filearr[count($filearr) - 1];

	// Generate the thumbnail of the requested image.
  $image->generateThumbnail($env, $thumbfile, $image->width, $image->height, $op, $compression);

  // TODO: stupid way to get to the tmp folder...
  $src = '/thumbs/' . $thumbfile;

  // Generate the image's url.
  if (isset($attributes['url'])) {
    $thumb = $src;
  }
  else {
    $thumb = '<img alt="' . $alt . '" class="image ' . (isset($attributes['class']) ? $attributes['class'] : '') .  '" ' . 'alt="' . $filename . '" ' . $tooltip . 'src="' . $src . '" />';
  }

  // If a link is set, wrap the image in the link.
  if (isset($attributes['link'])) {
    $link = empty($attributes['link']) ? ($node->name . '/' . $file) : $attributes['link'];
    $thumb = '<a ' .
      (empty($title) ? '' : ('title="' . $title . '"')) .
      'class="linked-image ' . (isset($attributes['class']) ? ('linked-image-' . $attributes['class']) : '') .  '"' .
    ' href="/' . $link . '">' . $thumb . '</a>';
  }

  return $thumb;
}

/**
 * Returns the of an IMGTHUMB.
 * @param $attributes
 * @param $file
 * @param $string
 * @param $env
 * @return string
 */
function wiki_IMGTHUMBURL($attributes, $file, $string, $env) {
  $attributes['url'] = TRUE;
  return wiki_IMGTHUMB($attributes, $file, $string, $env);
}
