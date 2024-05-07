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
function file_shadow_file_form(Environment $env, array $vars) {
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
function file_load_includes(Environment $env, array $vars) {
  $module_path = $env->getModulePath('file');
  $env->addInclude($module_path . '/assets/css/file.css');
}

/**
 * Implements hook_init().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function file_init(Environment $env, array $vars) {
  // There is a request to upload a file.
  // TODO: move in actions / factory?
  if (!empty($_FILES)) {
    FileObject::checkUploads($env);
    exit();
  }

  // There is a request to delete a file.
  // TODO: move in actions / factory?
  if (isset($_REQUEST[\Quanta\Common\Node::NODE_ACTION_DELETE_FILE])) {
    $node = NodeFactory::current($env);
    $split_file_to_delete = explode('/', $_REQUEST[\Quanta\Common\Node::NODE_ACTION_DELETE_FILE]);
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
function file_node_presave(Environment $env, array $vars) {
  /** @var Node $node */
  $node = $vars['node'];
  $data = $vars['data'];
  $uploaded_files = [];
  $isMultiple= !$data['single_file'];
  // Walk through uploaded files.
  foreach ($data as $label => $name) {
    $exp = explode('-', $label);
    if (array_shift($exp) == 'uploaded_file') {
      $form_name = array_shift($exp);
      $file = implode('-', $exp);
      $uploaded_files[$form_name][] = Api::normalizeFilePath($file);
    }
  }


  //
  foreach ($uploaded_files as $form_name => $uploaded_file_list) {
    // Save list of uploaded files for this file field in json.
    $node->json->{$form_name} = $isMultiple ? $uploaded_file_list : [$uploaded_file_list[count($uploaded_file_list) - 1]];
    if(!$isMultiple){
      $node->setThumbnail($uploaded_file_list[count($uploaded_file_list) - 1]);
      if(count($uploaded_file_list) > 1)
      FileObject::deleteFile($node, $uploaded_file_list[0]);
    }
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
function file_node_after_save(Environment $env, array $vars) {

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
        new Message($env, t('The file <b>!file</b> has an invalid name and could not be uploaded. Please use a different name.', array('!file' => $file)), \Quanta\Common\Message::MESSAGE_WARNING);
      }
      elseif (is_file($tmp_file_path)) {
        copy($tmp_file_path, $node->path . '/' . Api::normalizeFilePath($file));
        unlink($tmp_file_path);
      }
      else {
        new Message($env, t('The file <b>!file</b> is invalid and could not be uploaded.', array('!file' => $file)), \Quanta\Common\Message::MESSAGE_WARNING);
      }
    }
    rmdir($tmp_dir);
  }
  }
}

/**
 * Implements hook_page_init().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function file_page_init(Environment $env, array $vars) {
  // If a Shadow is open, initialize file upload libraries.
	if (Shadow::isOpen($env)) {
    /** @var Page $page */
    $page = $vars['page'];
    // TODO: print is not a good approach.
    $attributes = array('file_inline' => TRUE, 'refresh' => TRUE, 'module' => 'file');
    print new \Quanta\Qtags\Js($env, $attributes, '/assets/js/jquery.knob.js');
    print new \Quanta\Qtags\Js($env, $attributes, '/assets/js/jquery.iframe-transport.js');
    print new \Quanta\Qtags\Js($env, $attributes, '/assets/js/jquery.fileupload.js');
    print new \Quanta\Qtags\Js($env, $attributes, '/assets/js/file-upload.js');
  }
}
