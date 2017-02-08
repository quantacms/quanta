<?php
/**
 * Detect images and eventually create HTML.
 */
function wiki_GALLERY($attributes, $value, $string, $env) {
  $theme = 'gallery';
  $filelist = new FileList($env, $value, $theme, $attributes + array('file_types', 'image'));
	$output = $filelist->render();
  return $output;
}
