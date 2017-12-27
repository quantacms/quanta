<?php
/**
 * Implements hook_shadow_node_edit.
 * @param $vars
 */
function workbench_shadow_node_form($vars) {
  $vars['shadow']->addTab('manage status', file_get_contents('core/workbench/tpl/status_form.inc'), 2);
}

/**
 * Implements hook_init().
 *
 * Create all default statuses if they don't exist yet.
 * @param $vars
 */
function workbench_init($vars) {

  $basic_statuses = array(
    NODE_STATUS_DRAFT => array('title' => 'Draft'),
    NODE_STATUS_UNPUBLISHED => array('title' => 'Unpublished'),
    NODE_STATUS_PUBLISHED => array('title' => 'Published'),
  );

  foreach ($basic_statuses as $status => $statusdata) {
    if (!is_dir($vars['env']->dir['statuses'] . '/' . $status)) {
      NodeFactory::buildNode($vars['env'], $status, '_statuses', $statusdata);
    }
  }

}

function workbench_node_presave($vars) {
  $vars['node']->setStatus($vars['data']['edit-status']);

}