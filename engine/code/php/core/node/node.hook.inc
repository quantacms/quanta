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
  $has_access = $user->checkAccess($vars['shadow']->getNode(), $vars['env']->getContext());
  $vars['shadow']->setAccess($has_access);
}

/**
 * Implementation of hook_action_node_add.
 * There is a node add request.
 * @param $vars
 */
function node_action_node_add($vars) {
  $response_json = NodeFactory::requestAction($vars['env'], $vars['data']['action'], $vars['data']);
  exit($response_json);
}

/**
 * Implementation of hook_action_node_edit.
 * There is a node edit request.
 * @param $vars
 */
function node_action_node_edit($vars) {
  $response_json = NodeFactory::requestAction($vars['env'], $vars['data']['action'], $vars['data']);
  exit($response_json);
}


/**
 * Implementation of hook_action_node_delete.
 * There is a node delete request.
 * @param $vars
 */
function node_action_node_delete($vars) {
  $response_json = NodeFactory::requestAction($vars['env'], $vars['data']['action'], $vars['data']);
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

  $node = NodeFactory::current($vars['env']);

  // If the node does not exist, redirect to 404.
  if (!$node->exists && !isset($_REQUEST['shadow']) && $node->name != '404') {
    $node_404 = NodeFactory::load($vars['env'], '404');
    if ($node_404->exists) {
      redirect('/404');
    } else {
      die('404 - Page not found.');
    }
  }
  // If user can't access the node, redirect to 403.
  elseif ($node->isForbidden() && !isset($_REQUEST['shadow']) ) {
    $node_403 = NodeFactory::load($vars['env'], '403');
    if ($node_403->exists) {
      redirect('/403');
    } else {
      die('403 - Forbidden.');
    }
  }

  else {
    new Message($vars['env'], 'user is viewing this page: ' . $node->name . '.', MESSAGE_GENERIC, MESSAGE_TYPE_LOG, 'node');
    // BUILD Template and content.
    $node->buildTemplate();
  }

}

/**
 * Implements hook_shadow_node_edit.
 * @param $vars
 */
function node_shadow_node_form($vars) {
  $action_name = ($vars['env']->getContext() == NODE_ACTION_ADD ? 'create' : 'edit') . ' content';
  $vars['shadow']->addTab($action_name, file_get_contents('core/node/tpl/node_edit.inc'), 1);
  $vars['shadow']->addTab('manage metadata', file_get_contents('core/node/tpl/metadata_form.inc'), 2);
  $vars['shadow']->addButton('edit-save', '<span style="color:green">&check;&nbsp;</span> Save');

}

/**
 * Implements hook_shadow_node_edit.
 * @param $vars
 */
function node_shadow_node_delete_form($vars) {
  $user = User::current($vars['env']);
  $node = $vars['shadow']->getNode();
  $has_access = $user->checkAccess($node, $vars['env']->getContext());
  if (!$has_access) {
    new Message($vars['env'], 'User ' . $user->name . ' attempted to delete a node without access', MESSAGE_WARNING, MESSAGE_TYPE_LOG, 'node');
  }
  else {
    $vars['shadow']->addTab('Delete', $node->renderDeleteForm($vars['env']->getContext()), 1);
  }
  $vars['shadow']->addButton('edit-save', '<span style="color:green">&check;&nbsp;</span> Delete');

}

/**
 * Implements hook_body_classes.
 * @param $vars
 */
function node_metadata($vars) {
  // Setup body classes per each item in the current node lineage.
  $node = NodeFactory::current($vars['env']);
  $vars['page']->addData('metadata', array(
    '<meta name="description" content="' . $node->getTeaser() . '" />',
    '<meta name="author" content="[ATTRIBUTE|name=title:' . $node->getAuthor() . ']" />',
    '<meta name="copyright" content="Aldo Tripiciano" />',
    '<meta name="application-name" content="Quanta CMS" />',
    '<meta property="fb:app_id" content="123352434839142" />',
    '<meta property="og:title" content="' . $node->getTitle() . '" />',
    '<meta property="og:type" content="article" />',
    '<meta property="og:image" content="[URL]' . $node->getThumbnail() . '" />',
    '<meta property="og:url" content="[URL]" />',
    '<meta property="og:description" content="' . $node->getTeaser() . '" />',
    '<meta name="twitter:card" content="' . $node->getTeaser() . '" />',
    '<meta name="twitter:title" content="' . $node->getTitle() . '" />',
    '<meta name="twitter:description" content="' . $node->getTeaser() . '" />',
    '<meta name="twitter:image" content="[URL]' . $node->getThumbnail() . '" />',
  ));
}

/**
 * Implements hook_body_classes.
 * @param $vars
 */
function node_body_classes($vars) {
  // Setup body classes per each item in the current node lineage.
  // TODO: show
  $node = NodeFactory::current($vars['env']);
  $node->buildLineage();
  $lineage = $node->getLineage();
  foreach ($lineage as $lineage_node) {
    $vars['page']->addData('body_classes', array('section-' . $lineage_node->name));
  }
  $vars['page']->addData('body_classes', array('page-' . $node->name));
}
