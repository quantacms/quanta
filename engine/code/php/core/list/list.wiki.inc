<?php

/**
* Detect content LIST and eventually create HTML.
*/
function wiki_LIST($attributes, $path, $string, $page) {
  $dirlist = new DirList($page->env, $path, 'dir', $attributes);

  return $dirlist->render();
}

/**
 * Detect content BLOG and eventually create HTML.
 */
function wiki_FILETABLE($attributes, $value, $string, $page) {
  $filelist = new FileList($page->env, $value, 'file_table', $attributes);
  return $filelist->render();
}

/**
 * Detect content FILES and eventually create HTML.
 */
function wiki_FILES($attributes, $value, $string, $page) {
  $filelist = new FileList($page->env, $value, 'file', $attributes);
  return $filelist->render();
}
