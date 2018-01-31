<?php

/**
 * Implements hook_shadow_file_form().
 *
 * @param $vars
 * Miscellaneous environment / page variables.
 */
function file_shadow_file_form($vars) {
  $node = $vars['shadow']->getNode();
  if (!Access::check($vars['env'], $vars['env']->getContext(), array('node' => $node))) {
    return FALSE;
  }
  $vars['shadow']->addTab('manage files', file_get_contents('core/file/tpl/upload_form.inc'), 2);
}

/**
 * Implements hook_load_includes().
 *
 * @param $vars
 *   Miscellaneous environment / page variables.
 */
function file_load_includes($vars) {
  $vars['env']->addInclude('engine/code/php/core/file/css/file.css');
}

/**
 * Implements hook_init().
 * @param $vars
 */
function file_init($vars) {

  $node = NodeFactory::current($vars['env']);

  // There is a request to upload a file.
  // TODO: move in actions / factory?
  if (isset($_FILES['upl'])) {
    File::checkUploads($node);
    exit();
  }

  // There is a request to delete a file.
  // TODO: move in actions / factory?
  if (isset($_REQUEST[NODE_ACTION_DELETE_FILE])) {
    $split_file_to_delete = explode('/', $_REQUEST[NODE_ACTION_DELETE_FILE]);
    File::deleteFile($node, $split_file_to_delete[count($split_file_to_delete) - 1]);
    exit();
  }

}

/**
 * Saves all files uploaded on the node.
 * @param $vars
 */
function file_node_save($vars) {
  // TODO: move in File or create FileFactory module.

	if (empty($vars['node']->getData('tmp_files_dir'))) {
        new Message($vars['node']->env, 'Warning: the temporary files dir ' . $vars['node']->getData('tmp_files_dir') . ' is empty.', MESSAGE_WARNING);

	}
  $tmp_dir = $vars['node']->env->dir['tmp_files'] . '/' . $vars['node']->getData('tmp_files_dir');
  if (is_dir($tmp_dir)) {
    $tmp_files = $vars['node']->env->scanDirectory($tmp_dir);
    foreach ($tmp_files as $file) {
      $fpath = $tmp_dir . '/' . $file;
      $fileobj = new File($vars['node']->env, $fpath, $vars['node']);
      if (!$fileobj->isPublic()) {
        new Message($vars['node']->env, 'The file <b>' . $file . '</b> has an invalid name. Please use a different name.', MESSAGE_WARNING);
      } elseif (is_file($fpath)) {
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

  // When saving a node, select the pre-created temporary files dir.
  if (!empty($_REQUEST['json']) && ($json = json_decode($_REQUEST['json'])) && isset($json->tmp_files_dir)) {
    $vars['node']->setData('tmp_files_dir', $json->tmp_files_dir);
  }
  else {
    $vars['node']->setData('tmp_files_dir', $vars['node']->getName() . '-' . time() . '-' . rand(0, 1000));
  }
}
