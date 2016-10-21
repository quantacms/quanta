<?php

function post_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/post/js/post.js');
  $vars['env']->addInclude('engine/code/php/core/post/css/post.css');
}

function post_shadow_post_add($vars) {

    $user = User::current($vars['env']);
    // ADD a post.
    if ($vars['shadow']->getContext() == NODE_ACTION_ADD) {
      $father = new Node($vars['env'], $vars['env']->getRequestedPath());
      $node = new Node($vars['env'], NULL, $father);
      $has_access = $user->checkAccess($father, $vars['shadow']->getContext());
    }
    // EDIT a node.
    else {
      $node = new Node($vars['env'], $vars['env']->getRequestedPath());
      $has_access = $user->checkAccess($node, $vars['shadow']->getContext());
    }
    $action_name = ($vars['shadow']->getContext() == NODE_ACTION_ADD ? 'create' : 'edit') . ' content';

    if (!$has_access) {
      new Message($vars['env'], 'User ' . $user->name . ' attempted to ' . $action_name . ' a node without access', MESSAGE_WARNING, MESSAGE_TYPE_LOG, 'node');
    }
    else {
      $vars['shadow']->addTab($action_name, $node->renderEditForm($vars['shadow']->getContext()), 1);
    }
    $vars['shadow']->addButton('edit-save', '<span style="color:green">&check;&nbsp;</span> Save');

  }