<?php
namespace Quanta\Common;

/**
 * Implements hook_shadow_FORMID_form().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function file_shadow_file_form($env, $vars) {
  /** @var Shadow $shadow */
  $shadow = $vars['shadow'];
  $node = $shadow->getNode();
  if (NodeAccess::check($env, $env->getContext(), array('node' => $node))) {
    $vars['shadow']->addTab('manage files', file_get_contents($env->getModulePath('file') . '/tpl/upload_form.html'), 2);
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
  $module_path = $env->getModulePath('file');
  $env->addInclude($module_path . '/css/file.css');
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
  if (!empty($_FILES)) {
    FileObject::checkUploads($env);
    exit();
  }

  // There is a request to delete a file.
  // TODO: move in actions / factory?
  if (isset($_REQUEST[NODE_ACTION_DELETE_FILE])) {
    $split_file_to_delete = explode('/', $_REQUEST[NODE_ACTION_DELETE_FILE]);
    FileObject::deleteFile($node, $split_file_to_delete[count($split_file_to_delete) - 1]);
    exit();
  }
}

/**
 * Implements hook_node_presave().
 *
 * Saves all files uploaded on the node.
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function file_node_presave($env, $vars) {
  // TODO: move in File or create FileFactory module.
  /** @var Node $node */
  $node = $vars['node'];
  $data = $vars['data'];
  $uploaded_files = [];
  // Walk through uploaded files.
  foreach ($data as $label => $name) {
    $exp = explode('-', $label);
    if (array_shift($exp) == 'uploaded_file') {
      $form_name = array_shift($exp);
      $file = implode('-', $exp);
      $uploaded_files[$form_name][] = Api::normalizeFilePath($file);
    }
  }

  foreach ($uploaded_files as $form_name => $uploaded_file_list) {
    // Save list of uploaded files for this file field in json.
    $node->json->{$form_name} = $uploaded_file_list;
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
function file_node_after_save($env, $vars) {

  // TODO: move in File or create FileFactory module.
  /** @var Node $node */
  $node = $vars['node'];
	if (!empty($node->getData('tmp_files_dir'))) {
  $tmp_dir = $env->dir['tmp_files'] . '/' . $node->getData('tmp_files_dir');
  if (is_dir($tmp_dir)) {
    $tmp_files = $env->scanDirectory($tmp_dir);
    foreach ($tmp_files as $file) {

      $tmp_file_path = $tmp_dir . '/' . $file;

      $fileobj = new FileObject($env, $tmp_file_path, $node);
      // Check that uploaded files are valid.
      if (!$fileobj->isPublic()) {
        new Message($env, 'The file <b>' . $file . '</b> has an invalid name and could not be uploaded. Please use a different name.', MESSAGE_WARNING);
      }
      elseif (is_file($tmp_file_path)) {
        copy($tmp_file_path, $node->path . '/' . Api::normalizeFilePath($file));
        unlink($tmp_file_path);
      }
      else {
        new Message($env, 'The file <b>' . $file . '</b> is invalid and could not be uploaded.', MESSAGE_WARNING);
      }
    }
    rmdir($tmp_dir);
  }
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
    $node->setData('tmp_files_dir', $node->getName() . '-' . $env->getData('timestamp'));
  }
}
