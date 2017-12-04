<?php
/**
 * Implements hook_init().
 * @param $vars
 */
function manager_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/manager/js/manager.js');
  $vars['env']->addInclude('engine/code/php/core/manager/css/manager.css');
  if ($vars['env']->getRequestedPath() == 'expand') {
    $node = NodeFactory::load($vars['env'], $_GET['node']);
    $manager = new Manager($vars['env'], $node);
    print $manager->renderTree($_GET['path']);

    exit;
  }
}

/**
 * Implement hook_shadow_node_edit().
 * @param $vars
 * @return bool
 */
function manager_shadow_manager_form($vars) {
  $user = UserFactory::current($vars['env']);
  $node = $vars['shadow']->getNode();
  if (!$user->checkAccess($node, $vars['env']->getContext())) {
    return FALSE;
  }
  $manager = new Manager($vars['env'], $node);
  $vars['shadow']->addTab('manage categories', '<h2>Manage Categories</h2>' . '<div class="shadow-hint">In this screen you can select the categories in which you want to include the node.</div>' . $manager->renderTree(), 4, 'manager-tree');
}

/**
 * Implements hook_action_node_add().
 * @param $vars
 */
function manager_action_node_add($vars) {
  // TODO: bugged on node add because we do not know the name of the node!
  // manager_action_node_edit($vars);
}

/**
 * Implements hook_node_save().
 * Update all the symlinks that the user selected as checkboxes
 * in the manager tree.
 *
 * @param $vars
 *   All the needed data.
 */
function manager_node_save($vars) {
  foreach($vars['data'] as $label => $val) {
    // If the user has selected a checkbox in the manager, attempt to create a symlink.
    if (substr($label, 0, 8) == 'add-leaf') {
      $leaf = substr($label, 9);
      NodeFactory::linkNodes($vars['env'], $vars['node']->getName(), $leaf, array('if_exists' => 'ignore'));
    }
    // If the user has deselected a checkbox in the manager, attempt to remove the symlink.
    elseif (substr($label, 0, 8) == 'rem-leaf') {
      $leaf = substr($label, 9);
      NodeFactory::unlinkNodes($vars['env'], $leaf, $vars['node']->getName());
    }
  }
}
