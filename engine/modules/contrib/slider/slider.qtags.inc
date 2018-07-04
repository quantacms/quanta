<?php

/**
 * Implements qtag SLIDESHOW.
 *
 * Creates a slideshow.
 * TODO: Old / deprecated...
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
function qtag_SLIDESHOW($env, $target, $attributes) {
  $dirlist = new DirList($env, $target, 'quanta-slider', array('sortbytime' => 'asc') + $attributes, 'slider');
  return $dirlist->render();
}
