<?php
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

function manager_shadow_manager($vars) {
  $user = User::current($vars['env']);
  $vars['shadow']->addTab('Insert your data', 'Manager', 1);
  $vars['shadow']->addButton('edit-save', 'Manager stuff');
}

function manager_shadow_node_add($vars) {
  manager_shadow_node_edit($vars);
}

function manager_shadow_node_edit($vars) {
  $user = User::current($vars['env']);
  $node = $vars['env']->getData('node');
  if (!$user->checkAccess($node, $vars['shadow']->getContext())) {
    return FALSE;
  }
  $manager = new Manager($vars['env'], $node);
  $vars['shadow']->addTab('manage categories', $manager->renderTree(), 3, 'manager-tree');
}

