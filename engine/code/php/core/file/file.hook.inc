<?php

/**
 * Implements hook_shadow_node_edit().
 * @param $vars
 * @return bool
 */
function file_shadow_file_form($vars) {
  $user = User::current($vars['env']);
  $node = $vars['shadow']->getNode();
  if (!$user->checkAccess($node, $vars['shadow']->getContext())) {
    return FALSE;
  }
  $vars['shadow']->addTab('manage files', file_get_contents('core/file/tpl/upload_form.inc'), 2);
}

/**
 * Implements hook_init().
 * @param $vars
 */
function file_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/file/css/file.css');
  $vars['env']->addInclude('engine/code/php/core/file/js/jquery.fileupload.js');
  $vars['env']->addInclude('engine/code/php/core/file/js/file-upload.js');
  $node = Node::current($vars['env']);

  if (isset($_FILES['upl'])) {
    File::checkUploads($node);
    exit();
  }

  if (isset($_REQUEST[NODE_ACTION_DELETE_FILE])) {
    $split_file_to_delete = explode('/', $_REQUEST[NODE_ACTION_DELETE_FILE]);
    File::deleteFile($node, $split_file_to_delete[count($split_file_to_delete) - 1]);
    exit();
  }

}

/**
 * Saves all files uploaded on the node.
 */
function file_node_save($vars) {
  // TODO: move in File or create FileFactory module.

  $tmp_dir = $vars['node']->env->dir['tmp_files'] . '/' . $vars['node']->tmp_files_dir;
  if (is_dir($tmp_dir)) {
    $tmp_files = $vars['node']->env->scanDirectory($tmp_dir);
    foreach ($tmp_files as $file) {
      $fpath = $tmp_dir . '/' . $file;
      $fileobj = new File($vars['node']->env, $fpath, $vars['node']);
      if (!$fileobj->isPublic()) {
        new Message($vars['node']->env, 'The file <b>' . $file . '</b> has an invalid name. Please use a different name.', MESSAGE_WARNING);
      }
      elseif (is_file($fpath)) {
        copy($fpath, $vars['node']->realpath . '/' . $file);
        unlink($fpath);
      }
    }
    rmdir($tmp_dir);
  }
}

/**
 * @param $vars
 */
function file_node_load($vars) {
  $vars['node']->tmp_files_dir = $vars['node']->getName() . '-' . time() . '-' . rand(0, 1000);
}