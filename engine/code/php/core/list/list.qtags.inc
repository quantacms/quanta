<?php

/**
 * Implements LIST qtag.
 */
function qtag_LIST($env, $target, $attributes) {
  $dirlist = new DirList($env, $target, 'dir', $attributes);
  $render = $dirlist->render();
  return $render;
}

/**
 * Implements FILETABLE qtag.
 *
 * Create a table list of files.
 */
function qtag_FILETABLE($env, $target, $attributes) {
  $filelist = new FileList($env, $target, 'file_table', $attributes, 'list');
  return $filelist->render();
}

/**
 * Implements FILES qtag.
 *
 * Create a list of files.
 */
function qtag_FILES($env, $target, $attributes) {
  $filelist = new FileList($env, $target, 'file', $attributes);
  return $filelist->render();
}

/**
 * Implements TREE qtag.
 *
 * Renders a tree (a list, that goes deep into folders).
 */
function qtag_TREE($env, $target, $attributes) {
  $dirlist = new DirList($env, $target, 'tree', $attributes);
  $render = $dirlist->render();
  return $render;
}