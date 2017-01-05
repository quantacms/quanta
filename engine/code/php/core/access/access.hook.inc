<?php

/**
 * Implements hook_shadow_node_edit.
 * @param $vars
 */
function access_shadow_access_form($vars) {
  $vars['shadow']->addTab('manage permissions', file_get_contents('core/access/tpl/permissions_form.inc'), 5);

}

function access_node_presave($vars) {
  $node_permissions = array(
    'node_add',
    'node_edit',
    'node_delete',
    'node_view',
  );

  // TODO: review this process.
  foreach($node_permissions as $permission) {
    if (isset($vars['data'][$permission])) {
      $vars['node']->json->permissions->{$permission} = $vars['data'][$permission];
    }
  }
}

/**
 * Implements hook_node_load().
 *
 * @param $vars
 */
function access_node_open($vars) {
  if (!empty($vars['node'])) {
    $user = User::current($vars['env']);
    $has_access = $user->checkAccess($vars['node'], NODE_ACTION_VIEW);

    if (!$has_access) {
      $vars['node'] = NodeFactory::buildForbiddenNode($vars['env']);
    }
  }
}