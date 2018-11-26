<?php
/**
 * Implements hook_shadow_node_form().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function tpledit_shadow_node_form($env, $vars) {
  /** @var Shadow $shadow */
  $shadow = $vars['shadow'];
  // Edit the current template of the node.
  // For now, we will only allow editing existing tpl.html files, NOT creating new ones.
  $node = $shadow->getNode();
  $node->buildTemplate();
  if (is_file($node->getData('tpl_file'))) {
    $shadow->addTab('manage templates', file_get_contents('core/node/tpl/tpl_edit.inc'), 2);
  }
}

/**
 * Implements hook_action_node_edit().
 * @param $env
 * @param $vars
 */
function tpledit_action_node_edit($env, $vars) {
  // TODO: move elsewhere maybe?
  if (isset($vars['data']['edit-tpl-file'])) {
    NodeFactory::updateTemplate($env, $vars['data']['edit-tpl-file'], $vars['data']['edit-tpl']);
  }
}
