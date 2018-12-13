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
  $node = NodeFactory::current($env);
  // There is a request to upload a file.
  // TODO: move in actions / factory?
  if (!empty($_FILES)) {
    FileObject::checkUploads($env);
    exit();
  }
  $file_delete_act = \Quanta\Common\Node::NODE_ACTION_DELETE_FILE;
  // There is a request to delete a file.
  // TODO: move in actions / factory?
  if (isset($_REQUEST[$file_delete_act])) {
    $split_file_to_delete = explode('/', $_REQUEST[$file_delete_act]);
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
 * Implements hook_node_load().
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function file_node_load(Environment $env, array $vars) {
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
    $attributes = array('external');
    print new \Quanta\Qtags\Css($env, $attributes, 'https://cdnjs.cloudflare.com/ajax/libs/jodit/3.1.39/jodit.min.css');
    print new \Quanta\Qtags\Js($env, $attributes, 'https://cdnjs.cloudflare.com/ajax/libs/jodit/3.1.39/jodit.min.js');
    $attributes = array('inline' => TRUE, 'module' => 'jquery');
    print new \Quanta\Qtags\Js($env, $attributes, '/assets/js/jquery-ui.min.js');
    print new \Quanta\Qtags\Js($env, $attributes, '/assets/js/jquery.ui.widget.js');
    print new \Quanta\Qtags\Js($env, $attributes, '/assets/js/jquery.tablesorter.js');
    $attributes = array('inline' => TRUE, 'module' => 'file');
    print new \Quanta\Qtags\Js($env, $attributes, '/assets/js/jquery.knob.js');
    print new \Quanta\Qtags\Js($env, $attributes, '/assets/js/jquery.iframe-transport.js');
    print new \Quanta\Qtags\Js($env, $attributes, '/assets/js/jquery.fileupload.js');
    print new \Quanta\Qtags\Js($env, $attributes, '/assets/js/file-upload.js');
  }
}
