<?php
/**
 * Implements IMG qtag.
 *
 * Renders an image.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
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
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_THUMBNAIL($env, $target, $attributes) {
  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  $attributes['link'] = $node->getName();
	$attributes['node'] = $node->getName();
	return qtag_IMGTHUMB($env, $node->getThumbnail(), $attributes);
}

/**
 * Implements IMGTHUMB qtag.
 *
 * Create a thumbnail / edited version of an image on the fly.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_IMGTHUMB($env, $target, $attributes) {

  // TODO: redesign using some html wrapping function.
  $node = isset($attributes['node']) ? NodeFactory::load($env, $attributes['node']) : NodeFactory::current($env);

  // Load the image.
  $image = new Image($env, $target, $node);
  $image->loadAttributes($attributes);
  // Setup tooltip.
  $tooltip = isset($attributes['tooltip']) ? (' data-tooltip="' . $attributes['tooltip'] . '"') : NULL;

  // Setup compression level (JPEG / PNG).
  $compression = isset($attributes['compression']) ? $attributes['compression'] : 60;

  // Setup fallback image.
  $fallback = isset($attributes['fallback']) ? $attributes['fallback'] : NULL;

	
	if (isset($attributes['alt'])) {
	  $alt = $attributes['alt'];
	}
	else {
    $split_target = explode('.', $target);
    $alt = str_replace('-', ' ', $split_target[0]);
	}
  // Setup alt for the image.

  // Setup title for the image link (if present).
  $link_title = isset($attributes['link_title']) ? $attributes['link_title'] : NULL;

  // Setup operations to run on the image.
  $op = isset($attributes['operation']) ? $attributes['operation'] : 'scale';

	$filearr = explode('/', $target);
	$filename = $filearr[count($filearr) - 1];

	// Generate the thumbnail of the requested image.
  $newthumbfile = $image->generateThumbnail($env, $image->width, $image->height, $op, $compression, $fallback);

  // TODO: stupid way to get to the tmp folder...
  $src = '/thumbs/' . $newthumbfile;

  // Generate the image's url.
  if (isset($attributes['url'])) {
    $thumb = $src;
  }
  else {
    // Generate the HTML of the thumbnail.
    $thumb = '<img width="' . $image->width . '" height="' . $image->height . '" alt="' . $alt . '" class="image ' . (isset($attributes['class']) ? $attributes['class'] : '') .  '" ' . $tooltip . ' src="' . $src . '" />';
  }

  // If a link is set, wrap the image in the link.
  if (isset($attributes['link'])) {
    $link = empty($attributes['link']) ? ($node->name . '/' . $target) : $attributes['link'];
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
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_IMGTHUMBURL($env, $target, $attributes) {
  $attributes['url'] = TRUE;
  return qtag_IMGTHUMB($env, $target, $attributes);
}
