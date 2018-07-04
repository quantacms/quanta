<?php
/**
 * Implements hook_shadow_node_edit().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   Miscellaneous variables.
 */
function ecommerce_shadow_product_form($env, $vars) {
  $user = UserFactory::current($vars['env']);
  $node = NodeFactory::current($env);
  if (!$user->checkAccess($node, NODE_ACTION_EDIT)) {
    return FALSE;
  }
  $vars['shadow']->addTab('product information', file_get_contents('core/ecommerce/tpl/ecommerce_form.inc'), 2);
}


/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param $vars
 *   An array of variables.
 */
function ecommerce_load_includes($env, $vars) {
  $module_path = $env->getModulePath('ecommerce');
  $env->addInclude($module_path . '/js/ecommerce.js');
  $env->addInclude($module_path . '/css/ecommerce.css');
}

/**
 * Implements hook_node_save().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   Mixed variables.
 */
function ecommerce_node_presave($env, $vars) {
  if (isset($vars['data']['price'])) {
    $vars['node']->json->price = $vars['data']['price'];
    $vars['node']->json->availability = $vars['data']['availability'];
    $vars['node']->json->stock = $vars['data']['stock'];
  }
}
