<?php
/**
 * Implements IMG qtag.
 *
 * Renders an image.
 */
function qtag_IMG($env, $target, $attributes) {
  $node =  NodeFactory::current($env);
  $image = new Image($env, $target, $node);
  $image->loadAttributes($attributes);
  return $image->render();
}

/**
 * Implements THUMBNAIL qtag.
 *
 * Renders the thumbnail image of a node.
 */
function qtag_THUMBNAIL($env, $target, $attributes) {
  $node = empty($name) ? NodeFactory::current($env) : NodeFactory::load($env, $name);
  $attributes['link'] = $node->getName();
	$attributes['node'] = $node->getName();
	return wiki_IMGTHUMB($env, $node->getThumbnail(), $attributes);
}

/**
 * Implements IMGTHUMB qtag.
 *
 * Create a thumbnail / edited version of an image on the fly.
 */
function qtag_IMGTHUMB($env, $target, $attributes) {
  $node = isset($attributes['node']) ? NodeFactory::load($env, $attributes['node']) : NodeFactory::current($env);

  // Load the image.
  $image = new Image($env, $target, $node);
  $image->loadAttributes($attributes);

  // Setup tooltip.
  $tooltip = isset($attributes['tooltip']) ? (' data-tooltip="' . $attributes['tooltip'] . '"') : NULL;

  // Setup compression level (JPEG / PNG).
  $compression = isset($attributes['compression']) ? $attributes['compression'] : 60;

  // Setup alt for the image.
  $alt = isset($attributes['alt']) ? $attributes['alt'] : $target;


  // Setup title for the image link (if present).
  $link_title = isset($attributes['link_title']) ? $attributes['link_title'] : NULL;

  // Setup operations to run on the image.
  $op = isset($attributes['operation']) ? $attributes['operation'] : 'scale';

  $thumbfile = 'thumb-' . str_replace(' ', '-', str_replace('/', '-', $node->getName() . '-' . $image->width . 'x' . $image->height . '-' . $target));
  preg_replace("/[^A-Za-z0-9 ]/", '', $thumbfile);
	$filearr = explode('/', $target);
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
    // Generate the HTML of the thumbnail.
    $thumb = '<img alt="' . $alt . '" class="image ' . (isset($attributes['class']) ? $attributes['class'] : '') .  '" ' . 'alt="' . $filename . '" ' . $tooltip . 'src="' . $src . '" />';
  }

  // If a link is set, wrap the image in the link.
  if (isset($attributes['link'])) {
    $link = empty($attributes['link']) ? ($node->name . '/' . $file) : $attributes['link'];
    $thumb = '<a ' .
      (empty($link_title) ? '' : ('title="' . $link_title . '"')) .
      'class="linked-image ' . (isset($attributes['class']) ? ('linked-image-' . $attributes['class']) : '') .  '"' .
    ' href="/' . $link . '">' . $thumb . '</a>';
  }

  return $thumb;
}

/**
 * Implements IMGTHUMBURL qtag.
 *
 * Returns the url of an IMGTHUMB.
 */
function qtag_IMGTHUMBURL($env, $target, $attributes) {
  $attributes['url'] = TRUE;
  return qtag_IMGTHUMB($env, $target, $attributes);
}
