<?php

function file_shadow_node_edit($vars) {
  $user = User::current($vars['env']);
  $node = $vars['env']->getData('node');
  if (!$user->checkAccess($node, $vars['shadow']->getContext())) {
    return FALSE;
  }

  $filelist = new FileList($vars['env'], $vars['shadow']->getContext() == NODE_ACTION_ADD ? NULL : $vars['env']->getRequestedPath(), 'file_admin');

  $tmp_files = $node->getName() . '-' . time() . '-' . rand(0, 1000);
  // TODO: access and refactor
  $form = '
  <form id="upload" method="POST" enctype="multipart/form-data">
  <input type="hidden" name="tmp_files" value="'. $tmp_files .'" />
  <input type="hidden" name="thumbnail" value="" />
  <div id="drop">Drop here files<a>or press here</a><input type="file" name="upl" id="file-upload" multiple /></div>' . $filelist->render() . '</form>';

  $vars['shadow']->addTab('manage files', '<div class="shadow-hint">Hint: after uploading files, use the FILES tag to include this list of files in content.</div><div id="edit-files">' . $form . '</div>', 2);

}