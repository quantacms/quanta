<?php

/**
* Detect content LIST and eventually create HTML.
*/
function wiki_LIST($replace, $attributes, $path, $string, $page) {
  $dirlist = new DirList($page->env, $path, 'dir', $attributes);
  $output = $dirlist->render();
  $string = str_replace($replace, $output, $string);
  return $string;
}

/**
 * Detect content BLOG and eventually create HTML.
 */
function wiki_BLOG($replace, $attributes, $value, $string, $page) {
  $dirlist = new DirList($page->env, $value, 'blog', array('sortbytime') + $attributes);
  $output = $dirlist->render();
  $string = str_replace($replace, $output, $string);
  return $string;
}

/**
 * Detect content BLOG and eventually create HTML.
 */
function wiki_SLIDER($replace, $attributes, $value, $string, $page) {
  $dirlist = new DirList($page->env, $value, 'slider', array('sortbytime') + $attributes);
  $output = $dirlist->render();
  $string = str_replace($replace, $output, $string);
  return $string;
}

/**
 * Detect content FILES and eventually create HTML.
 */
function wiki_FILES($replace, $attributes, $value, $string, $page) {
  $filelist = new FileList($page->env, $value, 'file', $attributes);
  $output = $filelist->render();
  $string = str_replace($replace, $output, $string);
  return $string;
}
