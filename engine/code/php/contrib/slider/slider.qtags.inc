<?php

/**
 * Implements qtag SLIDESHOW.
 *
 * Creates a slideshow.
 * TODO: Old / deprecated...
 */
function qtag_SLIDESHOW($env, $target, $attributes) {
  $dirlist = new DirList($env, $target, 'quanta-slider', array('sortbytime' => 'asc') + $attributes, 'slider');
  return $dirlist->render();
}
