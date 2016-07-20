<?php

function file_shadow_node_add($vars) {
  file_shadow_node_edit($vars);
}

function file_shadow_node_edit($vars) {
  $user = User::current($vars['env']);
  $node = $vars['env']->getData('node');
  if (!$user->checkAccess($node, $vars['shadow']->getContext())) {
    return FALSE;
  }

  // TODO: access and refactor
  $vars['shadow']->addTab('manage files', $node->renderUploadForm($vars['shadow']->getContext()), 2);

}

function file_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/file/css/file.css');
}
