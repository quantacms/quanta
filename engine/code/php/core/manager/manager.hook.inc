<?php

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function manager_load_includes($env, $vars) {
  $env->addInclude('engine/code/php/core/manager/js/manager.js');
  $env->addInclude('engine/code/php/core/manager/css/manager.css');
}

/**
 * Implements hook_init().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function manager_init($env, $vars) {
  if ($env->getRequestedPath() == 'expand') {
    $node = NodeFactory::load($env, $_GET['node']);
    $manager = new Manager($env, $node);
    print $manager->renderTree($_GET['path']);
    exit;
  }
}

/**
 * Implement hook_shadow_FORMID_form().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables..
 */
function manager_shadow_manager_form($env, $vars) {
  /** @var Shadow $shadow */
  $shadow = $vars['shadow'];

  $node = $shadow->getNode();
  $manager = new Manager($env, $node);
  $manager_cats = $shadow->getData('manager');
  if (empty($manager_cats)) {
    $manager_cats = 'root';
  }

  $shadow->addTab('manage categories', '<h2>Manage Categories</h2>' . '<div class="shadow-hint">In this screen you can select the categories in which you want to include the node.</div>' . $manager->renderTree($manager_cats), 4, 'manager-tree');
}

/**
 * Implements hook_node_add_complete().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function manager_node_add_complete($env, $vars) {
  // TODO: bugged on node add because we do not know the name of the node!
  manager_node_edit_complete($env, $vars);
}

/**
 * Implements hook_node_save().
 * Update all the symlinks that the user selected as checkboxes
 * in the manager tree.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function manager_node_edit_complete($env, &$vars) {
  /** @var Node $node */
  $node = $vars['node'];

  foreach($vars['data'] as $label => $val) {
    // If the user has selected a checkbox in the manager, attempt to create a symlink.
    if (substr($label, 0, 8) == 'add-leaf') {
      $leaf = substr($label, 9);
      NodeFactory::linkNodes($env, $node->getName(), $leaf, array('if_exists' => 'ignore'));
    }
    // If the user has deselected a checkbox in the manager, attempt to remove the symlink.
    elseif (substr($label, 0, 8) == 'rem-leaf') {

      $leaf = substr($label, 9);
      NodeFactory::unlinkNodes($env, $node->getName(), $leaf);
    }
  }
}

/**
 * Implements hook_link_alter().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function manager_link_alter($env, $vars) {
  // TODO: bugged on node add because we do not know the name of the node!
  if (isset($vars['attributes']['manager'])) {
    $vars['link_data'][] = 'data-manager="' . $vars['attributes']['manager'] . '"';
  }
}


