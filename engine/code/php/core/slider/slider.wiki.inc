<?php

/**
 * Detect content SLIDER and eventually create HTML.
 */
function wiki_SLIDESHOW($attributes, $value, $string, $env) {
  $dirlist = new Slider($env, $value, 'slider', array('sortbytime' => 'asc') + $attributes);
  return $dirlist->render();
}
