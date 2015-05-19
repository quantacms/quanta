<?php

function file_shadow_node_edit($vars) {
  $user = User::current($vars['env']);
  if (!$user->checkAccess($vars['env']->getData('node'), $vars['shadow']->getContext())) {
    return FALSE;
  }

  $filelist = new FileList($vars['env'], $vars['env']->getRequestedPath(), 'file_admin');
  if ($vars['shadow']->getContext() == NODE_ACTION_ADD) {
    $filelist->clearFiles();
    $tmp_files = $vars['env']->getData('node')->getName() . '-' . time() . '-' . rand(0, 1000);
  } else {
    $tmp_files = $vars['env']->getRequestedPath();
  }
  // TODO: access
  $form = '
  <form id="upload" method="POST" enctype="multipart/form-data">
  <input type="hidden" name="tmp_files" value="'. $tmp_files .'" />
  <div id="drop">Drop here files<a>or press here</a><input type="file" name="upl" multiple /></div>' . $filelist->render() . '</form>';

  $vars['shadow']->addTab('manage files', '<div class="shadow-hint">Hint: after uploading files, use the FILES tag to include this list of files in content.</div><div id="edit-files">' . $form . '</div>', 2);

}