<?php
/**
 * Implementation of hook_action_node_add.
 * There is a node add request.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   An array of variables.
 */
function node_action_node_add($env, $vars) {
  $response_json = NodeFactory::requestAction($env, $vars['data']['action'], $vars['data']);
  exit($response_json);
}

/**
 * Implements hook_action_node_edit.
 * There is a node edit request.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   An array of variables.
 */
function node_action_node_edit($env, $vars) {
  $response_json = NodeFactory::requestAction($env, $vars['data']['action'], $vars['data']);
  exit($response_json);
}


/**
 * Implements hook_action_node_delete.
 * There is a node delete request.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   An array of variables.
 */
function node_action_node_delete($env, $vars) {
  $response_json = NodeFactory::requestAction($env, $vars['data']['action'], $vars['data']);
  exit($response_json);
}

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   An array of variables.
 */
function node_load_includes($env, $vars) {
  $env->addInclude('engine/code/php/core/node/js/node.js');
  $env->addInclude('engine/code/php/core/node/css/node.css');
}

/**
 * Implemens hook_init().
 * Starts a node corresponding to the current page.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   An array of variables.
 */
function node_init($env, $vars) {

  $node = NodeFactory::current($env);
  // If the node does not exist, redirect to 404.
  if (!$node->exists && !isset($_REQUEST['shadow']) && $node->name != '404') {
    $node_404 = NodeFactory::load($env, '404');
    if ($node_404->exists) {
      redirect('/404');
    }
    else {
      die('404 - Page not found.');
    }
  }
  // If user can't access the node, redirect to 403.
  else if ($env->request_path != '403' && $node->isForbidden() && !isset($_REQUEST['shadow'])) {
    $node_403 = NodeFactory::load($env, '403');
    if ($node_403->exists) {
      redirect('/403');
    }
    else {
      die('403 - Forbidden.');
    }
  }

}

/**
 * Implements hook_shadow_node_form().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   An array of variables.
 */
function node_shadow_node_form($env, $vars) {
  $action_name = ($env->getContext() == NODE_ACTION_ADD ? 'create' : 'edit') . ' content';
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
 * Implements hook_shadow_node_delete_form().
 *
 *  @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   An array of variables.
 */
function node_shadow_node_delete_form($env, $vars) {
  $node = $vars['shadow']->getNode();
  $has_access = Access::check($env, $env->getContext(), array('node' => $node));
  if (!$has_access) {
    // TODO: move this in access check!
    new Message($env, 'User attempted to delete a node without access', MESSAGE_WARNING, MESSAGE_TYPE_LOG, 'node');
  }
  else {
    $vars['shadow']->addTab('Delete', $node->renderDeleteForm($env->getContext()), 1);
  }
  $vars['shadow']->addButton('edit-save', '<span style="color:green">&check;&nbsp;</span> Delete');

}

/**
 * Implements hook_metadata().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   An array of variables.
 */
function node_metadata($env, $vars) {
  // Setup body classes per each item in the current node lineage.
  $node = NodeFactory::current($env);
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
 * Implements hook_body_classes().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   An array of variables.
 */
function node_body_classes($env, $vars) {
  // Setup body classes per each item in the current node lineage.
  // TODO: show
  $node = NodeFactory::current($env);
  $node->buildLineage();
  $lineage = $node->getLineage();
  foreach ($lineage as $lineage_node) {
    $vars['page']->addData('body_classes', array ('section-' . $lineage_node->name));
  }
  $vars['page']->addData('body_classes', array ('page-' . $node->name));
}
