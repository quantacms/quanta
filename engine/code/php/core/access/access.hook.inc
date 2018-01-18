<?php

/**
 * Implements hook_shadow_node_edit.
 * @param $vars
 */
function access_shadow_access_form($vars) {
  /** @var Shadow $shadow */
  $shadow = &$vars['shadow'];
  $shadow->addTab('manage permissions', file_get_contents('core/access/tpl/permissions_form.inc'), 5);

}

/**
 * @param $vars
 */
function access_node_presave($vars) {

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
 * @param $vars
 */
function access_node_open($vars) {
  /** @var Node $node */
  $node = &$vars['node'];
  /** @var Environment $env */
  $env = &$vars['env'];

  if (!empty($node) && $node->exists) {
    $user = UserFactory::current($env);
    $has_access = $user->checkAccess($node, NODE_ACTION_VIEW);
    if (!$has_access) {
      $node = NodeFactory::buildForbiddenNode($env);
    }
  }
}

/**
 * Render a box.
 * TODO: make it open for all node attributes
 * @param $vars
 */
function access_wikitag($vars) {
  $node = NodeFactory::current($vars['env']);
  $user = UserFactory::current($vars['env']);

  // The user has selected to show the tag only on certain paths / lineage.
  if (!empty($vars['attributes']['filter'])) {

// [ADD|filter=path@aldo;dario,
      $filter_match = _access_filter($vars['env'], $vars['attributes']['filter'], $node, $user);
      // If there is an active tag filter, and it doesn't match, empty the tag.
      foreach ($vars['wikitag'] as $tag => $content) {
        if (!$filter_match) {
          $vars['wikitag'][$tag] = '';
        }
      }
    }


}

function _access_filter($env, $criteria, $node, $user = NULL) {

  if ($user == NULL) {
    $user = new User($env, $node->getAuthor());
  }
  $filter = explode(',', $criteria);

  foreach ($filter as $k => $filter_item) {
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
        foreach ($lineage as $k => $lineage_node) {
          $filter_check[] = $lineage_node->getName();
        }
        break;

      case 'category':
        $filter_check = array();
        $categories = $node->getCategories();
        foreach ($categories as $k => $category_node) {
          $filter_check[] = $category_node->getName();
        }
        break;

      default:

        break;
    }

    if (!empty($filter_check)) {
      // Check if the filtered item corresponds to the user input.
      foreach ($filter_check as $k => $check) {

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