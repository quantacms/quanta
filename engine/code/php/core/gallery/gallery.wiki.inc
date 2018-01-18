<?php
/**
 * Detect images and eventually create HTML.
 * @param $attributes
 * @param $value
 * @param $string
 * @param $env
 * @return bool|mixed|string
 */
function wiki_GALLERY($attributes, $value, $string, $env) {
  $theme = 'gallery';
  $filelist = new FileList($env, $value, $theme, $attributes + array('file_types', 'image'), 'gallery');
	$output = $filelist->render();
  return $output;
}
