<?php

/**
 * Detect content SLIDER and eventually create HTML.
 * @param $attributes
 * @param $value
 * @param $string
 * @param $env
 * @return bool|mixed|string
 */
function wiki_SLIDESHOW($attributes, $value, $string, $env) {
  $dirlist = new DirList($env, $value, 'quanta-slider', array('sortbytime' => 'asc') + $attributes, 'slider');
  return $dirlist->render();
}
