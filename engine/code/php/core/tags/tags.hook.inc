<?php
function tags_shadow_node_edit($vars) {
  $user = User::current($vars['env']);
  if (!$user->checkAccess($vars['env']->getData('node'), $vars['shadow']->getContext())) {
    return FALSE;
  }
  $form = '
  <form id="tags" method="POST" enctype="multipart/form-data">
  <div id="tags">Add tags here</form>';

  $vars['shadow']->addTab('manage tags', '<div class="shadow-hint">Hint: after uploading files, use the FILES tag to include this list of files in content.</div><div id="edit-tags">' . $form . '</div>', 3);

}