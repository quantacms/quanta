<?php

/**
 * Implements hook_shadow_node_edit().
 * @param $vars
 * @return bool
 */
function ecommerce_shadow_product_form($vars) {
  $user = User::current($vars['env']);
  $node = NodeFactory::current($env);
  if (!$user->checkAccess($node, NODE_ACTION_EDIT)) {
    return FALSE;
  }
  $vars['shadow']->addTab('product information', file_get_contents('core/ecommerce/tpl/ecommerce_form.inc'), 2);
}

function ecommerce_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/ecommerce/js/ecommerce.js');
  $vars['env']->addInclude('engine/code/php/core/ecommerce/css/ecommerce.css');
}

/**
 * Implements hook_node_save().
 * @param $vars
 */
function ecommerce_node_presave($vars) {
  if (isset($vars['data']['price'])) {
    $vars['node']->json->price = $vars['data']['price'];
    $vars['node']->json->availability = $vars['data']['availability'];
    $vars['node']->json->stock = $vars['data']['stock'];
  }
}