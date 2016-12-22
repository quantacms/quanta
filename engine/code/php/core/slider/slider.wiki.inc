<?php

/**
 * Detect content SLIDER and eventually create HTML.
 */
function wiki_SLIDESHOW($attributes, $value, $string, $env) {
  $dirlist = new DirList($env, $value, 'slider', array('sortbytime' => 'asc') + $attributes, 'slider');
  return $dirlist->render();
}
