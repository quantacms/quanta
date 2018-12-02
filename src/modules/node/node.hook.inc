<?php
namespace Quanta\Common;

/**
 * Implementation of hook_action_node_add.
 * There is a node add request.
 *
 * @param Environment $env
 *   The Environment.
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
 * @param array $vars
 *   An array of variables.
 */
function node_load_includes($env, $vars) {
  $module_path = $env->getModulePath('node');

  $env->addInclude($module_path . '/js/node.js');
  $env->addInclude($module_path . '/css/node.css');
}

/**
 * Implemens hook_init().
 * Starts a node corresponding to the current page.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function node_init($env, $vars) {

  $node = NodeFactory::current($env);
  // If the node does not exist, redirect to 404.
  if (!$node->exists && !isset($_REQUEST['shadow']) && $node->name != '404') {
    $node_404 = NodeFactory::load($env, '404');
    if ($node_404->exists) {
      Api::redirect('/404');
    }
    else {
      die('404 - Page not found.');
    }
  }
  // If user can't access the node, redirect to 403.
  else if ($env->request_path != '403' && $node->isForbidden() && !isset($_REQUEST['shadow'])) {
    $node_403 = NodeFactory::load($env, '403');
    if ($node_403->exists) {
      Api::redirect('/403');
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
 * @param array $vars
 *   An array of variables.
 */
function node_shadow_node_form($env, $vars) {
  /** @var Shadow $shadow */
  $shadow = $vars['shadow'];
  $action_name = ($env->getContext() == NODE_ACTION_ADD ? 'create' : 'edit') . ' content';

  $shadow->addTab($action_name, file_get_contents($env->getModulePath('node') . '/tpl/node_edit.inc'), 1);
  $shadow->addTab('manage metadata', file_get_contents($env->getModulePath('node') . '/tpl/metadata_form.inc'), 2);
}

/**
 * Implements hook_shadow_node_delete_form().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function node_shadow_node_delete_form($env, $vars) {
  /** @var Shadow $shadow */
  $shadow = $vars['shadow'];
  $node = $shadow->getNode();

  $has_access = NodeAccess::check($env, $env->getContext(), array('node' => $node));
  if (!$has_access) {
    // TODO: move this in access check!
    new Message($env, 'User attempted to delete a node without access', MESSAGE_WARNING, MESSAGE_TYPE_LOG, 'node');
  }
  else {
    $shadow->addTab('Confirm Delete', $node->renderDeleteForm(), 1);
  }
  $shadow->addButton('edit-save', '<span style="color:green">&check;&nbsp;</span> Delete');

}

/**
 * Implements hook_metadata().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function node_metadata($env, $vars) {
  $node = NodeFactory::current($env);
  $author = UserFactory::load($env, $node->getAuthor());

  $vars['metadata']['charset'] = array(
    'content' => 'utf-8',
  );

  if (!empty($node->getTeaser())) {
    // Meta description.
    $vars['metadata']['description'] = array(
      'content' => Api::filter_xss($node->getTeaser()),
    );
    // Twitter description.
    $vars['metadata']['twitter:description'] = array(
      'content' => Api::filter_xss($node->getTeaser()),
    );
    // Twitter card.
    $vars['metadata']['twitter:card'] = array(
      'content' => Api::filter_xss($node->getTeaser()),
    );
    // OG description.
    $vars['metadata']['og:description'] = array(
      'property' => 'og:description',
      'content' => Api::filter_xss($node->getTeaser()),
    );
  }

  if (!empty($node->getKeywords())) {
    $vars['metadata']['keywords'] = array(
      'content' => (implode(',', $node->getKeywords())),
    );
  }

  $vars['metadata']['name'] = array(
    'content' => Api::filter_xss($author->getTitle()),
  );

  $vars['metadata']['application-name'] = array(
    'content' => t('Quanta CMS'),
  );

  // OG metadata.
  $vars['metadata']['og:title'] = array(
    'property' => 'og:title',
    'content' => Api::filter_xss($node->getTitle()),
  );

  $vars['metadata']['og:type'] = array(
    'property' => 'og:type',
    'content' => 'article',
  );

  $vars['metadata']['og:url'] = array(
    'property' => 'og:url',
    'content' => $env->getData('request_url'),
  );

  if (!empty($node->getThumbnail())) {
    $vars['metadata']['og:image'] = array(
      'property' => 'og:image',
      'content' => $env->getData('request_url') . $node->getThumbnail(),
    );

    $vars['metadata']['twitter:image'] = array(
      'content' => $env->getData('request_url') . $node->getThumbnail(),
    );
  }




  $vars['metadata']['twitter:title'] = array(
    'content' => Api::filter_xss($node->getTitle()),
  );



}

/**
 * Implements hook_body_classes().
 *
 * @param Environment $env
 *   The Environment.
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
    $vars['page']->addData('body_classes', array('section-' . $lineage_node->name));
  }
  $vars['page']->addData('body_classes', array('page-' . $node->name));
}
