<?php
namespace Quanta\Common;

/**
 * Implements hook_shadow_node_edit.
 *
 * @param Environment $env
 *   The current Environment.
 * @param $vars
 *   An array of variables.
 */
function access_shadow_node_permissions(Environment $env, array $vars){
  /** @var Shadow $shadow */
  $shadow = &$vars['shadow'];
  $module_path = $env->getModulePath('access');
  $tab_contents =  file_get_contents($module_path . '/tpl/node_permissions.html');
  $shadow->addTab('manage permissions',$tab_contents, 5);
}

/**
 * Implements hook_node_presave().
 *
 * @param Environment $env
 *   The current Environment.
 * @param $vars
 *   An array of variables.
 */
function access_node_presave(Environment $env, array $vars){
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
    $node->json->permissions = new \stdClass();
  }
  // Set all the node's permissions.
  foreach ($node_permissions as $permission) {
    if (isset($vars['data'][$permission])) {
      $node->json->permissions->{$permission} = $vars['data'][$permission];
    }
  }
}

/**
 * Implements hook_node_open().
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function access_node_open(Environment $env, array $vars) {
  /** @var Node $node */
  $node = &$vars['node'];

  // Check if the user can access the node.
  if (!empty($node) && $node->exists) {
    $has_access = NodeAccess::check($env, \Quanta\Common\Node::NODE_ACTION_VIEW, array('node' => $node));
    if (!$has_access) {
      $node = NodeFactory::buildForbiddenNode($env);
    }
  }
}

/**
 * Check if a qtag is filtered via the filter attribute.
 *
 * TODO: make it open for all node attributes
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function access_qtag_preload(Environment $env, array $vars) {
  $node = NodeFactory::current($env);
  $user = UserFactory::current($env);
  /** @var Qtag $qtag */
  $qtag = &$vars['qtag'];

  // The user has selected to show the tag only if a certain criteria is matched.
  if (!empty($qtag->getAttribute('filter'))) {
    $filter_match = _access_filter($env, $qtag->getAttribute('filter'), $node, $user);
    // If there is an active tag filter, and it doesn't match, empty the tag.
    if (!$filter_match) {
      $qtag->setAccess(FALSE);
    }
  }
}

/**
 * Check access as defined by a qtag filter attribute.
 *
 * @param Environment $env
 *   The Environment.
 * @param string $criteria
 *   The criteria to use for filtering check.
 * @param Node $node
 *   The node to use for filtering check.
 * @param User $user
 *   The user to use for filtering check.
 * @return bool
 *   Returns true if the access check was positive.
 */
function _access_filter($env, $criteria, $node, $user = NULL) {
  // Explode the criteria values.
  $filter = explode(',', $criteria);

  // Make matching false by default.
  $filter_match = FALSE;

  if ($user == NULL) {
    $user = UserFactory::load($env, $node->getName());
  }

  // Check all filters.
  foreach ($filter as $filter_item) {
    $exp = explode('@', $filter_item);
    $filter_type = $exp[0];
    if (substr($filter_type, 0, 12) == 'current_user') {
      $filter_type = str_replace('current_user', 'user', $filter_type);
      $user = UserFactory::current($env);
    }
    if (substr($filter_type, 0, 12) == 'node_author') {
      $filter_type = str_replace('node_author', 'user', $filter_type);
      $user = new User($env, $node->getAuthor());
    }
    $filter_values = explode(';', $exp[1]);

    $filter_match = FALSE;
    $break = FALSE;
    $one_match = FALSE;
   
   

    switch ($filter_type) {
      // Filter by HTTP referer.
      case 'referer':
        $explode = explode('/', $_SERVER['HTTP_REFERER']);
        $filter_check = $explode;
        break;
      // Filter by current node path.
      case 'author':
        $filter_check = array($node->getAuthor());
        break;
      // Filter by current node path.
      case 'path':
        $filter_check = array($node->getName());
        break;
      // Filter by current node path.
      case 'language':
        $filter_check = array(Localization::getLanguage($env));
        break;
      // Filter by current node's father node.
      case 'father':
        $filter_check = array($node->getFather()->getName());
        break;
      // Filter by current user's name.
      case 'user_name':
        $filter_check = array($user->getName());
        break;
      // Filter by current user's role.
			case 'status':
        $filter_check = array($node->getStatus());
				break;
			case 'user_role':
        $filter_check = $user->getRoles();
        break;
      // Filter by node's lineage (father, grandfather, and all ancestors!).
      case 'lineage':
        $one_match = TRUE;
        $filter_check = array();
        $lineage = $node->getLineage();

        foreach ($lineage as $lineage_node) {
          /** @var Node $lineage_node */
          $filter_check[] = $lineage_node->getName();
        }
        $filter_check[] = $node->getName();

        break;

      case 'context':
        $filter_check[] = $env->getContext();
        break;

      case 'category':
        $filter_check = array();
        $categories = $node->getCategories();
        foreach ($categories as $category_node) {
          /** @var Node $category_node */
          $filter_check[] = $category_node->getName();
        }
        break;

      case 'thumbnail':
        $filter_check = array($node->getThumbnail());
        break;

      default:
        if (!empty($filter_type) && !empty($node->getAttributeJSON($filter_type))) {
		$filter_check[] = $node->getAttributeJSON($filter_type);
        }
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

	  // If comparing with an array of values, check if the value is in the array.
	  if (is_array($check)) {
		  $filter_match = in_array($filter_value, $check);
	  }
    elseif($filter_value == "__empty__"){
      $filter_match = !$check; 
    }
	  else {
		  $filter_match = ($check == $filter_value);
	  }
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
    else {
      $filter_match = FALSE;
    }

  }
  return $filter_match;
}
