<?php
namespace Quanta\Common;

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function list_load_includes(Environment $env, array $vars) {
  $module_path = $env->getModulePath('list');
  $env->addInclude($module_path . '/assets/js/list.js');
  $env->addInclude($module_path . '/assets/css/list.css');
}


/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function list_action_node_weight_update(Environment $env, array $vars) {
  $nodes = ($vars['data']['nodes']);
  $user = UserFactory::current($env);
  foreach ($nodes as $k => $node_item) {
    $node = NodeFactory::load($env, $node_item);
    // Only change weight / alter nodes if user has the right to do so.
    if (NodeAccess::check($env, \Quanta\Common\Node::NODE_ACTION_EDIT, array('node' => $node))) {
      // Start weight at 1.
      $node->setWeight($k + 1);
      $node->save();
    }
  }
  exit(json_encode(array('no_redirect' => 'true')) );
}