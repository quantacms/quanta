<?php
/**
 * Detect images and eventually create HTML.
 */
function wiki_GALLERY($attributes, $value, $string, $env) {
  $theme = isset($attr[1]) ? $attributes[1] : 'gallery';
  $filelist = new FileList($env, $value, $theme, array('file_types', 'image'));
  $output = $filelist->render();
  return $output;
}
