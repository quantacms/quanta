<?php

/**
 * @param Environment $env
 *   The environment.
 *
 * @param array $vars
 *   Miscellaneous variables.
 */
function post_init($env, $vars) {
  $vars['env']->addInclude('engine/code/php/core/post/js/post.js');
  $vars['env']->addInclude('engine/code/php/core/post/css/post.css');
}

/**
 * @param Environment $env
 *   The environment.
 *
 * @param array $vars
 *   Miscellaneous variables.
 */
function post_shadow_post_add($env, $vars) {
    $user = UserFactory::current($vars['env']);
    // ADD a post.
    if ($vars['shadow']->getContext() == NODE_ACTION_ADD) {
      $father = NodeFactory::current($env);
      $node = new Node($vars['env'], NULL, $father);
      $has_access = $user->checkAccess($father, $vars['env']->getContext());
    }
    // EDIT a node.
    else {
      $node = new Node($vars['env'], $vars['env']->getRequestedPath());
      $has_access = $user->checkAccess($node, $vars['env']->getContext());
    }
    $action_name = ($vars['env']->getContext() == NODE_ACTION_ADD ? 'create' : 'edit') . ' content';

    if (!$has_access) {
      new Message($vars['env'], 'User ' . $user->name . ' attempted to ' . $action_name . ' a node without access', MESSAGE_WARNING, MESSAGE_TYPE_LOG, 'node');
    }
    else {
      $vars['shadow']->addTab($action_name, $node->renderEditForm($vars['env']->getContext()), 1);
    }
    $vars['shadow']->addButton('edit-save', '<span style="color:green">&check;&nbsp;</span> Save');

  }
