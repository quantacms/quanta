<?php
/**
 * Implements hook_shadow_node_form().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function workflow_shadow_node_form($env, $vars) {

  $vars['shadow']->addTab('manage status', file_get_contents($env->getModulePath('workflow') . '/tpl/status_form.html'), 2);
}

/**
 * Implements hook_setup().
 *
 * Create all default statuses if they don't exist yet.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function workflow_setup($env, $vars) {
  // Initialize default statuses.
  $basic_statuses = array(
    NODE_STATUS_DRAFT => array('title' => 'Draft'),
    NODE_STATUS_UNPUBLISHED => array('title' => 'Unpublished'),
    NODE_STATUS_PUBLISHED => array('title' => 'Published'),
  );

  foreach ($basic_statuses as $status => $statusdata) {
    if (!is_dir($env->dir['statuses'] . '/' . $status)) {
      NodeFactory::buildNode($env, $status, '_statuses', $statusdata);
    }
  }

}

/**
 * Implements hook_node_presave().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function workflow_node_presave($env, $vars) {
  if (isset($vars['data']['edit-status'])) {
    $vars['node']->setStatus($vars['data']['edit-status']);
  }
}
