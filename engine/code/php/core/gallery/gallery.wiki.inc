<?php
/**
 * Detect images and eventually create HTML.
 */
function wiki_GALLERY($replace, $attributes, $value, $string, $page) {
  $theme = isset($attr[1]) ? $attributes[1] : 'gallery';
  $filelist = new FileList($page->env, $value, $theme, array('file_types', 'image'));
  $output = $filelist->render();
  $string = str_replace($replace, $output, $string);
  return $string;
}
