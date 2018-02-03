<?php

/**
 * Implements hook_shadow_node_edit.
 *
 * @param Environment $env
 *   The current Environment.
 *
 * @param $vars
 *   An array of variables.
 */
function access_shadow_access_form($env, $vars) {
  /** @var Shadow $shadow */
  $shadow = &$vars['shadow'];
  $shadow->addTab('manage permissions', file_get_contents('core/access/tpl/permissions_form.inc'), 5);

}

/**
 * Implements hook_node_presave().
 *
 * @param Environment $env
 *   The current Environment.
 *
 * @param $vars
 *   An array of variables.
 */
function access_node_presave($env, $vars) {

  $node_permissions = array(
    'node_add',
    'node_edit',
    'node_delete',
    'node_view',
  );
  /** @var Node $node */
  $node = &$vars['node'];
  // TODO: review this process.
  if (!isset($node->json->permissions)) {
    $node->json->permissions = new StdClass();
  }
  foreach ($node_permissions as $permission) {
    if (isset($vars['data'][$permission])) {
      $node->json->permissions->{$permission} = $vars['data'][$permission];
    }
  }
}

/**
 * Implements hook_node_load().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param $vars
 *   An array of variables.
 */
function access_node_open($env, $vars) {
  /** @var Node $node */
  $node = &$vars['node'];

  // Check if the user can access the node.
  if (!empty($node) && $node->exists) {
    $has_access = Access::check($env, NODE_ACTION_VIEW, array('node' => $node));
    if (!$has_access) {
      $node = NodeFactory::buildForbiddenNode($env);
    }
  }
}

/**
 * Render a box.
 *
 * TODO: make it open for all node attributes
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param $vars
 *   An array of variables.
 */
function access_qtag($env, $vars) {
  $node = NodeFactory::current($env);
  $user = UserFactory::current($env);
  // The user has selected to show the tag only on certain paths / lineage.
  if (!empty($vars['attributes']['filter'])) {
     $filter_match = _access_filter($env, $vars['attributes']['filter'], $node, $user);
      // If there is an active tag filter, and it doesn't match, empty the tag.
      foreach ($vars['qtag'] as $tag => $content) {
        if (!$filter_match) {
          $vars['qtag'][$tag] = '';
        }
      }
    }


}

/**
 * Check access as defined by a qtag filter attribute.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $criteria
 *   The criteria to use for filtering check.
 *
 * @param Node $node
 *   The node to use for filtering check.
 *
 * @param User $user
 *   The user to use for filtering check.
 *
 * @return bool
 *   Returns true if the access check was positive.
 */
function _access_filter($env, $criteria, $node, $user = NULL) {

  if ($user == NULL) {
    $user = new User($env, $node->getAuthor());
  }
  // Explode the criteria values.
  $filter = explode(',', $criteria);

  // Make matching false by default.
  $filter_match = FALSE;

  foreach ($filter as $filter_item) {
    $exp = explode('@', $filter_item);
    $filter_type = $exp[0];
    $filter_values = explode(';', $exp[1]);
    $filter_match = FALSE;
    $break = FALSE;
    $one_match = FALSE;

    switch ($filter_type) {
      case 'referer':
        $explode = explode('/', $_SERVER['HTTP_REFERER']);
        $filter_check = $explode;
        break;
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
        $one_match = TRUE;
        $filter_check = array();
        $lineage = $node->getLineage();
        foreach ($lineage as $lineage_node) {
          /** @var Node $lineage_node */
          $filter_check[] = $lineage_node->getName();
        }
        break;

      case 'category':
        $filter_check = array();
        $categories = $node->getCategories();
        foreach ($categories as $category_node) {
          /** @var Node $category_node */
          $filter_check[] = $category_node->getName();
        }
        break;

      default:

        break;
    }

    if (!empty($filter_check)) {
      // Check if the filtered item corresponds to the user input.
      foreach ($filter_check as $check) {

        foreach ($filter_values as $filter_value) {

          if (substr($filter_value, 0, 1) == '!') {
            $negate = TRUE;
            $filter_value = str_replace('!', '', $filter_value);
          }

          $filter_match = ($check == $filter_value);

          if ($filter_match && !isset($negate)) {
            unset($one_match);
            $break = TRUE;
          }

          if (isset($negate)) {
            $filter_match = !$filter_match;
            unset($negate);

            if (!$filter_match) {
              $break = TRUE;
            }
          }

          if ($break) {
            break;
          }
        }

        // For multiple fields such as lineage one match is enough to validate.
        if ($break) {
          break;
        }
      }

      //TODO: handle invalid / unknown filters.
      //TODO: use hook for custom filters.

      if (!$filter_match) {
        break;
      }
    }

  }
  return $filter_match;
}