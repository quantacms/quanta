<?php
/**
 * Implements GALLERY qtag.
 *
 * Detect images in a node and eventually create a Gallery.
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
function qtag_GALLERY($env, $target, $attributes) {
  $theme = 'gallery';
  $filelist = new FileList($env, $target, $theme, $attributes + array('file_types', 'image'), 'gallery');
	$output = $filelist->render();
  return $output;
}
