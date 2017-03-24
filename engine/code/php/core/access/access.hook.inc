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
  foreach ($node_permissions as $permission) {
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


/**
 * Render a box.
 * TODO: make it open for all node attributes
 */
function access_wikitag($vars) {
  $node = NodeFactory::current($vars['env']);
  $user = User::current($vars['env']);

  // The user has selected to show the tag only on certain paths / lineage.
  if (!empty($vars['attributes']['filter'])) {

// [ADD|filter=path@aldo;dario,
    $filter = explode(',', $vars['attributes']['filter']);

    foreach ($filter as $k => $filter_item) {
      $exp = explode('@', $filter_item);
      $filter_type = $exp[0];
      $filter_values = array_flip(explode(';', $exp[1]));

      switch ($filter_type) {
        // show the tag only on certaing path...
        case 'path':
          $filter_check = array($node->getName());
          break;
        // show the tag only on certaing path...
        case 'father':
          $filter_check = array($node->getFather()->getName());
          break;
        case 'user':
          $filter_check = array($user->getName());
          break;

        case 'role':
          $filter_check = $user->getRoles();
          break;

        case 'lineage':
          $filter_check = array();
          $lineage = $node->getLineage();
          foreach ($lineage as $k => $lineage_node) {
            $filter_check[] = $lineage_node->getName();
          }
          break;
        default:

          break;
      }

      $filter_match = FALSE;

      // Check if the filtered item corresponds to the user input.
      foreach ($filter_check as $k => $check) {
        if (isset($filter_values[$check])) {
          $filter_match = TRUE;
        }
      }
      // If there is an active tag filter, and it doesn't match, empty the tag.
      foreach ($vars['wikitag'] as $tag => $content) {
        if (!$filter_match) {
          $vars['wikitag'][$tag] = '';
        }
      }
    }

  }
}
