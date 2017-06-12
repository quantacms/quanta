<?php

/**
* Detect content LIST and eventually create HTML.
*/
function wiki_LIST($attributes, $path, $string, $env) {
  $dirlist = new DirList($env, $path, 'dir', $attributes);
  $render = $dirlist->render();
  return $render;
}

/**
 * Detect content FILETABLE and eventually create HTML.
 */
function wiki_FILETABLE($attributes, $value, $string, $env) {
  $filelist = new FileList($env, $value, 'file_table', $attributes, 'list');
  return $filelist->render();
}

/**
 * Detect content FILES and eventually create HTML.
 */
function wiki_FILES($attributes, $value, $string, $env) {
  $filelist = new FileList($env, $value, 'file', $attributes);
  return $filelist->render();
}

/**
 * Detect content LIST and eventually create HTML.
 */
function wiki_TREE($attributes, $path, $string, $env) {
  $dirlist = new DirList($env, $path, 'tree', $attributes);
  $render = $dirlist->render();
  return $render;
}