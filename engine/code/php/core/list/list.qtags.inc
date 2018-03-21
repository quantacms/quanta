<?php

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
function qtag_LIST($env, $target, $attributes) {
  $dirlist = new DirList($env, $target, 'dir', $attributes);
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
function qtag_FILETABLE($env, $target, $attributes) {
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
function qtag_FILES($env, $target, $attributes) {
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
function qtag_FILES_ADMIN($env, $target, $attributes) {
  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  $filelist = new FileList($env, $target, 'file_admin', $attributes);

  // TODO: not optimal, but we don't want default files from father node on node add...
  if ($env->getContext() == NODE_ACTION_ADD) {
    $filelist->clear();
  }
  else {
    $filelist->generate();
  }

  if (isset($node->json->files)) {
    // Rearrange Files according with what was set in the node json.
    $files_json = array_flip($node->json->files);

    // Compare files found in the directory with files saved in the json
    // and reorder them accordingly.
    // This allows the user to keep the file order he has set via drag&drop.
    foreach ($filelist->list_items as $filename => $file) {
      $filelist->list_items[$files_json[$filename]] = $file;
      unset($filelist->list_items[$filename]);
    }
    ksort($filelist->list_items);
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
function qtag_TREE($env, $target, $attributes) {
  $dirlist = new DirList($env, $target, 'tree', $attributes);
  $render = $dirlist->render();
  return $render;
}
