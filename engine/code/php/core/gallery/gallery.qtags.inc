<?php
/**
 * Implements GALLERY qtag.
 *
 * Detect images in a node and eventually create a Gallery.
 */
function qtag_GALLERY($env, $target, $attributes) {
  $theme = 'gallery';
  $filelist = new FileList($env, $target, $theme, $attributes + array('file_types', 'image'), 'gallery');
	$output = $filelist->render();
  return $output;
}
