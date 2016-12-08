<?php
/**
 * Implements hook_init().
 * @param $vars
 */
function manager_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/manager/js/manager.js');
  $vars['env']->addInclude('engine/code/php/core/manager/css/manager.css');
  if ($vars['env']->getRequestedPath() == 'expand') {
    $node = new Node($vars['env'], $_GET['node']);
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
  $user = User::current($vars['env']);
  $node = $vars['env']->getData('node');
  if (!$user->checkAccess($node, $vars['shadow']->getContext())) {
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
 * @param $vars
 */
function manager_node_save($vars) {
  foreach($vars['data'] as $label => $val) {
    if (substr($label, 0, 8) == 'add-leaf') {
      $leaf = substr($label, 9);
      $leafnode = new Node($vars['env'], $leaf);
      if (!is_link($leafnode->realpath . '/' . $vars['node']->name)) {
        symlink($vars['node']->realpath, $leafnode->realpath . '/' . $vars['node']->name);
      }
    } elseif (substr($label, 0, 8) == 'rem-leaf') {
      $leaf = substr($label, 9);
      $leafnode = new Node($vars['env'], $leaf);
      if (is_link($leafnode->realpath . '/' . $vars['node']->name)) {
        unlink($leafnode->realpath . '/' . $vars['node']->name);
      }
    }
  }
}
