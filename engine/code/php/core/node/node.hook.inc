<?php
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
 * Implements hook_load_includes().
 *
 * @param $vars
 *   Miscellaneous environment / page variables.
 */
function node_load_includes($vars) {
  $vars['env']->addInclude('engine/code/php/core/node/js/node.js');
  $vars['env']->addInclude('engine/code/php/core/node/css/node.css');
}

/**
 * Implementation of hook_init.
 * Starts a node corresponding to the current page.
 * @param $vars
 */
function node_init($vars) {

  $node = NodeFactory::current($vars['env']);
  // If the node does not exist, redirect to 404.
  if (!$node->exists && !isset($_REQUEST['shadow']) && $node->name != '404') {
    $node_404 = NodeFactory::load($vars['env'], '404');
    if ($node_404->exists) {
      redirect('/404');
    }
    else {
      die('404 - Page not found.');
    }
  }
  // If user can't access the node, redirect to 403.
  else if ($vars['env']->request_path != '403' && $node->isForbidden() && !isset($_REQUEST['shadow'])) {
    $node_403 = NodeFactory::load($vars['env'], '403');
    if ($node_403->exists) {
      redirect('/403');
    }
    else {
      die('403 - Forbidden.');
    }
  }

}

/**
 * Implements hook_shadow_node_form.
 * @param $vars
 */
function node_shadow_node_form($vars) {
  $action_name = ($vars['env']->getContext() == NODE_ACTION_ADD ? 'create' : 'edit') . ' content';
  $vars['shadow']->addTab($action_name, file_get_contents('core/node/tpl/node_edit.inc'), 1);
  $vars['shadow']->addTab('manage metadata', file_get_contents('core/node/tpl/metadata_form.inc'), 2);
  $vars['shadow']->addButton('edit-save', '<span style="color:green">&check;&nbsp;</span> Save');

  // Edit the current template of the node.
  // For now, we will only allow editing existing tpl.html files, NOT creating new ones.
  $node = $vars['shadow']->getNode();
  $node->buildTemplate();
  if (is_file($node->getData('tpl_file'))) {
    $vars['shadow']->addTab('manage templates', file_get_contents('core/node/tpl/tpl_edit.inc'), 2);
  }
}

/**
 * Implements hook_shadow_node_delete_form.
 */
function node_shadow_node_delete_form($vars) {
  $node = $vars['shadow']->getNode();
  $has_access = Access::check($vars['env'], $vars['env']->getContext(), array('node' => $node));
  if (!$has_access) {
    // TODO: move this in access check!
    new Message($vars['env'], 'User attempted to delete a node without access', MESSAGE_WARNING, MESSAGE_TYPE_LOG, 'node');
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
  $vars['page']->addData('metadata', array (
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
    $vars['page']->addData('body_classes', array ('section-' . $lineage_node->name));
  }
  $vars['page']->addData('body_classes', array ('page-' . $node->name));
}
