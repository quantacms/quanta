<?php

/**
 * Implementation of hook_shadow_node_delete_access.
 * Check if user can edit the node before showing the shadow.
 * TODO: sharing the edit function.
 * @param $vars
 */
function node_shadow_node_add_access($vars) {
  return node_shadow_node_edit_access($vars);
}

/**
 * Implementation of hook_shadow_node_delete_access.
 * Check if user can delete the node before showing the shadow.
 * @param $vars
 */
function node_shadow_node_delete_access($vars) {
  return node_shadow_node_edit_access($vars);
}

function node_shadow_node_edit_access($vars) {
  $user = User::current($vars['env']);
  $has_access = $user->checkAccess($vars['shadow']->getNode(), $vars['shadow']->getContext());
  $vars['shadow']->setAccess($has_access);
}

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
 * Implementation of hook_action_node_delete.
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
  $vars['env']->addInclude('engine/code/php/core/node/js/node.js');
  $vars['env']->addInclude('engine/code/php/core/node/css/node.css');

  $user = User::current($vars['env']);
  $node = Node::current($vars['env']);

  // TODO: should go in file module?
  if (isset($_FILES['upl']) && ($user->checkAccess($node, NODE_ACTION_EDIT))) {
    $node->checkUploads();
    exit();
  }

  if (isset($_REQUEST[NODE_ACTION_DELETE_FILE]) && ($user->checkAccess($node, NODE_ACTION_EDIT))) {
    $split_file_to_delete = explode('/', $_REQUEST[NODE_ACTION_DELETE_FILE]);
    $node->deleteFile($split_file_to_delete[count($split_file_to_delete) - 1]);
    exit();
  }

  // Print the parsed HTML.
  if ($node->isEmpty()) {
    $vars['env']->setData('content', 'This is an empty page, here is nothing to show.');
    $vars['env']->setData('title', '404 - Empty page.');
  }
  elseif ($node->exists) {

    if ($user->checkAccess($node, NODE_ACTION_VIEW)) {

      new Message($vars['env'], 'user is viewing this page: ' . $node->name . '.', MESSAGE_GENERIC, MESSAGE_TYPE_LOG, 'node');

      // BUILD Breadcrumb - lineage.
      // TODO: right place to build bc?
      $breadcrumb = array();

      $node->buildLineage();
      foreach ($node->getLineage() as $n) {
        $breadcrumb[$n->getName()] = $n->getTitle();
      }
      $vars['env']->setData('breadcrumb', $breadcrumb);

      $vars['env']->setData('title', $node->getTitle());
      $vars['page']->setTitle($node->getTitle());

      // BUILD Template and content.
      $node->buildTemplate();
      $vars['page']->setContent($node->render());
    }
    else {
      $vars['page']->setTitle('Access denied');
      $vars['page']->setContent('You don\'t have access to this page.');
    }
  }
  $vars['env']->setData('node', $node);
  $vars['env']->hook('body_classes', array("page" => &$vars['page']));
  $vars['page']->vars['body-classes'] = implode(' ', $vars['page']->body_classes);
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
    $father = $vars['shadow']->getNode();
    $node = new Node($vars['env'], NULL, $father);
    $node->setName(NODE_NEW);
    print $node->getFather()->name;
    $has_access = $user->checkAccess($father, $vars['shadow']->getContext());
  }
  // EDIT a node.
  else {
    $node = $vars['shadow']->getNode();
    $has_access = $user->checkAccess($node, $vars['shadow']->getContext());
  }

  $vars['env']->setData('node', $node);

  $action_name = ($vars['shadow']->getContext() == NODE_ACTION_ADD ? 'create' : 'edit') . ' content';

  if (!$has_access) {
    new Message($vars['env'], 'User ' . $user->name . ' attempted to ' . $action_name . ' a node without access', MESSAGE_WARNING, MESSAGE_TYPE_LOG, 'node');
  }
  else {
    $vars['shadow']->addTab($action_name, $node->renderEditForm($vars['shadow']->getContext()), 1);
    $vars['shadow']->addTab('manage metadata', $node->renderMetadataForm($vars['shadow']->getContext()), 2);
    $vars['shadow']->addTab('manage files', $node->renderUploadForm($vars['shadow']->getContext()), 3);

  }
  $vars['shadow']->addButton('edit-save', '<span style="color:green">&check;&nbsp;</span> Save');

}


/**
 * Implements hook_shadow_node_edit.
 * @param $vars
 */
function node_shadow_node_delete($vars) {
  $node = new Node($vars['env'], $vars['env']->getRequestedPath());
  $user = User::current($vars['env']);
  $has_access = $user->checkAccess($node, $vars['shadow']->getContext());

  if (!$has_access) {
    new Message($vars['env'], 'User ' . $user->name . ' attempted to delete a node without access', MESSAGE_WARNING, MESSAGE_TYPE_LOG, 'node');
  }
  else {
    $vars['shadow']->addTab('Delete', $node->renderDeleteForm($vars['shadow']->getContext()), 1);
  }
  $vars['shadow']->addButton('edit-save', '<span style="color:green">&check;&nbsp;</span> Delete');

}

/**
 * Implements hook_body_classes.
 * @param $vars
 */
function node_body_classes($vars) {
  if ($vars['page']->env->getData('node')) {
    $vars['page']->addBodyClass('body-' . $vars['page']->env->getData('node')
      ->getName());

    $breadcrumb = $vars['env']->getData('breadcrumb');

    /*
     * foreach ($breadcrumb as $path => $title) {
      $vars['page']->addBodyClass('section-' . $path);
    }
    */
  }
}