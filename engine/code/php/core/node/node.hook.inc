<?php
/**
 * Implementation of hook_action_node_add.
 * There is a node add request.
 * @param $vars
 */
function node_action_node_add($vars) {
  $response_json = Node::requestAction($vars['env'], $vars['data']['action'], $vars['data']);
  exit($response_json);
}

/**
 * Implementation of hook_action_node_edit.
 * There is a node edit request.
 * @param $vars
 */
function node_action_node_edit($vars) {
  $response_json = Node::requestAction($vars['env'], $vars['data']['action'], $vars['data']);
  exit($response_json);
}


/**
 * Implementation of hook_action_node_add.
 * There is a node delete request.
 * @param $vars
 */
function node_action_node_delete($vars) {
  $response_json = Node::requestAction($vars['env'], $vars['data']['action'], $vars['data']);
  exit($response_json);
}

/**
 * Implementation of hook_init.
 * Starts a node corresponding to the current page.
 * @param $vars
 */
function node_init($vars) {
  $user = User::current($vars['env']);
  $node = new Node($vars['env'], $vars['env']->getRequestedPath());

  if (isset($_FILES['upl']) && ($user->checkAccess($node, NODE_ACTION_EDIT))) {
    $node->checkUploads();
    exit();
  }

  if (isset($_REQUEST[NODE_ACTION_DELETE_FILE])) {
    $split_file_to_delete = explode('/', $_GET['delete-file']);
    $node->deleteFile($split_file_to_delete[count($split_file_to_delete) - 1]);
    exit();
  }

  // Print the parsed HTML.
  if ($node->exists) {
    if ($user->checkAccess($node, NODE_ACTION_VIEW)) {
      new Message($vars['env'], 'user is viewing this page: ' . $node->name . '.', MESSAGE_GENERIC, MESSAGE_TYPE_LOG, 'node');

      // TODO: right place to build bc?
      $breadcrumb = array();
      $node->buildLineage();
      foreach ($node->getLineage() as $n) {
        $breadcrumb[$n->getName()] = $n->getTitle();
      }

      $vars['env']->setData('node', $node, 'node');
      $vars['env']->setData('breadcrumb', $breadcrumb, 'node');
      $vars['env']->setData('content', $node->render(), 'node');
      $vars['env']->setData('title', $node->getTitle(), 'node');
    }
    else {
      $vars['env']->setData('content', 'You don\'t have access to this page.', 'node');
      $vars['env']->setData('title', '403: Access Denied', 'node');
    }
  }
  else {
    $vars['env']->setData('content', 'This page doesn\'t exist.', 'node');
    $vars['env']->setData('title', '404: Page not found', 'node');
  }

}

/**
 * Implements hook_shadow_node_add.
 * Should alias to edit.
 * @param $vars
 */
function node_shadow_node_add($vars) {
  node_shadow_node_edit($vars);
}

/**
 * Implements hook_shadow_node_edit.
 * @param $vars
 */
function node_shadow_node_edit($vars) {
  $user = User::current($vars['env']);

  // ADD a node.
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
}


function node_body_classes($vars) {
  if ($vars['page']->env->getData('node')) {
    $vars['page']->addBodyClass('body-' . $vars['page']->env->getData('node')
      ->getName());
    foreach ($vars['page']->env->getData('breadcrumb') as $path => $title) {
      $vars['page']->addBodyClass('section-' . $path);
    }
  }
}