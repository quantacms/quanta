<?php
/**
 * Implements hook_shadow_node_edit.
 * @param $vars
 */
function access_shadow_node_edit($vars) {
  $father = new Node($vars['env'], $vars['env']->getRequestedPath());
  $node = new Node($vars['env'], NULL, $father);
  $vars['shadow']->addTab('manage permissions', $node->renderPermissionsForm($vars['shadow']->getContext()), 4);

}

function access_node_presave($vars) {
  $node_permissions = array(
    'node_add',
    'node_edit',
    'node_delete',
    'node_view',
  );

  foreach($node_permissions as $permission) {
    if (isset($vars['data'][$permission]))
    $vars['node']->json->permissions->{$permission} = $vars['data'][$permission];
  }
}
