<?php
namespace Quanta\Common;
/**
 * Implements LIST qtag.
 *
 * Renders a list of nodes (children of a given node).
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
function qtag_LIST($env, $target, &$attributes) {
  $tpl = isset($attributes['tpl']) ? $attributes['tpl'] : 'dir';
  $dirlist = new DirList($env, $target, $tpl, $attributes, 'list');  
  $render = $dirlist->render();
  return $render;
}

/**
 * Implements FILETABLE qtag.
 *
 * Create a table list of files.
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
function qtag_FILETABLE($env, $target, &$attributes) {
  $filelist = new FileList($env, $target, 'file_table', $attributes, 'list');
  return $filelist->render();
}

/**
 * Implements FILES qtag.
 *
 * Create a list of files.
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
function qtag_FILES($env, $target, &$attributes) {
  $filelist = new FileList($env, $target, 'file', $attributes);
  return $filelist->render();
}


/**
 * Implements FILES_ADMIN qtag.
 *
 * Renders a list of files in the "manage files" admin interface.
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
function qtag_FILES_ADMIN($env, $target, &$attributes) {
  $filelist = new FileList($env, $target, 'file_admin', $attributes);

  // TODO: not optimal, but we don't want default files from father node on node add...
  if ($env->getContext() == NODE_ACTION_ADD) {
    $filelist->clear();
  }
  else {
    $filelist->generate();
  }

  return $filelist->render();
}


/**
 * Implements TREE qtag.
 *
 * Renders a tree (a list, that goes deep into folders).
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
function qtag_TREE($env, $target, &$attributes) {
  $dirlist = new DirList($env, $target, 'tree', $attributes);
  $render = $dirlist->render();
  return $render;
}




/**
 * Implements BLOCKS qtag.
 *
 * Renders a list of rendered blocks (1st level nodes into selected folder).
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
function qtag_BLOCKS($env, $target, &$attributes) {
  $dirlist = new DirList($env, $target, 'blocks', $attributes, 'list');
  return $dirlist->render();
}