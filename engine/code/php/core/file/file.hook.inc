<?php

/**
 * Implements hook_shadow_FORMID_form().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function file_shadow_node_form($env, $vars) {
  /** @var Shadow $shadow */
  $shadow = $vars['shadow'];
  $node = $shadow->getNode();
  if (NodeAccess::check($env, $env->getContext(), array('node' => $node))) {
    $vars['shadow']->addTab('manage files', file_get_contents('core/file/tpl/upload_form.html'), 2);
  }
}

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function file_load_includes($env, $vars) {
  $env->addInclude('engine/code/php/core/file/css/file.css');
}

/**
 * Implements hook_init().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function file_init($env, $vars) {
  $node = NodeFactory::current($env);
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
 * Implements hook_node_save().
 *
 * Saves all files uploaded on the node.
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function file_node_save($env, $vars) {
  // TODO: move in File or create FileFactory module.
  /** @var Node $node */
  $node = $vars['node'];
	if (empty($node->getData('tmp_files_dir'))) {
        new Message($node->env, 'Warning: the temporary files dir ' . $node->getData('tmp_files_dir') . ' is empty.', MESSAGE_WARNING);

	}
  $tmp_dir = $env->dir['tmp_files'] . '/' . $node->getData('tmp_files_dir');
  if (is_dir($tmp_dir)) {
    $tmp_files = $env->scanDirectory($tmp_dir);
    foreach ($tmp_files as $file) {
      $fpath = $tmp_dir . '/' . $file;
      $fileobj = new File($env, $fpath, $node);
      if (!$fileobj->isPublic()) {
        new Message($env, 'The file <b>' . $file . '</b> has an invalid name. Please use a different name.', MESSAGE_WARNING);
      } elseif (is_file($fpath)) {
        copy($fpath, $node->realpath . '/' . $file);
        unlink($fpath);
      }
    }
    rmdir($tmp_dir);
  }
}

/**
 * Implements hook_node_load().
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function file_node_load($env, $vars) {
  /** @var Node $node */
  $node = $vars['node'];
  // When saving a node, select the pre-created temporary files dir.
  if (!empty($_REQUEST['json']) && ($json = json_decode($_REQUEST['json'])) && isset($json->tmp_files_dir)) {
    $node->setData('tmp_files_dir', array_pop($json->tmp_files_dir));
  }
  else {
    $node->setData('tmp_files_dir', $node->getName() . '-' . time() . '-' . rand(0, 1000));
  }
}
