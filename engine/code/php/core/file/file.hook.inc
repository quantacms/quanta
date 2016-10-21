<?php

/**
 * Implements hook_shadow_node_add().
 * @param $vars
 */
function file_shadow_node_add($vars) {
  file_shadow_node_edit($vars);
}

/**
 * Implements hook_shadow_node_edit().
 * @param $vars
 * @return bool
 */
function file_shadow_node_edit($vars) {
  $user = User::current($vars['env']);
  $node = $vars['env']->getData('node');
  if (!$user->checkAccess($node, $vars['shadow']->getContext())) {
    return FALSE;
  }
  $vars['shadow']->addTab('manage files', $node->renderUploadForm($vars['shadow']->getContext()), 2);
}

/**
 * Implements hook_init().
 * @param $vars
 */
function file_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/file/css/file.css');
  $vars['env']->addInclude('engine/code/php/core/file/js/jquery.fileupload.js');
  $vars['env']->addInclude('engine/code/php/core/file/js/file-upload.js');
}
