<?php

/**
 * Implements hook_shadow_node_edit.
 * @param $vars
 */
function access_shadow_access_form($vars) {
  $father = new Node($vars['env'], $vars['env']->getRequestedPath());
  $node = new Node($vars['env'], NULL, $father);
  $vars['shadow']->addTab('manage permissions', file_get_contents('core/access/tpl/permissions_form.inc'), 5);

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
