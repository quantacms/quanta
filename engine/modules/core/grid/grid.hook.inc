<?php

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function grid_load_includes($env, $vars) {
  $module_path = $env->getModulePath('grid');

  $env->addInclude($module_path . '/css/reset.css');
  $env->addInclude($module_path . '/css/grid.css');
  $env->addInclude($module_path . '/css/utilities.css');
}




function grid_list_item($env, $vars) {
  $is_grouped = !empty($vars['list']->getAttribute('grid-item-group'));

  if ($is_grouped) {
    $group_counter = $vars['list_item_counter'] % $vars['list']->getAttribute('grid-item-group');
  }

  if (!empty($vars['list']->getAttribute('grid-item-' . $vars['list_item_counter']))) {
    // Specific item.
    $vars['list_item_classes'][] = $vars['list']->getAttribute('grid-item-' . $vars['list_item_counter']);
  } elseif($is_grouped && !empty($vars['list']->getAttribute('grid-item-group-' . $group_counter))) {
    // Specific item in the interval.
    $vars['list_item_classes'][] = $vars['list']->getAttribute('grid-item-group-' . $group_counter);
  } elseif(!empty($vars['list']->getAttribute('grid-item-odd') && ($vars['list_item_counter'] % 2 == 1))) {
    // Odd items.
    $vars['list_item_classes'][] = $vars['list']->getAttribute('grid-item-odd');
  } elseif(!empty($vars['list']->getAttribute('grid-item-even') && ($vars['list_item_counter'] % 2 == 0))) {
    // Even items.
    $vars['list_item_classes'][] = $vars['list']->getAttribute('grid-item-even');
  } elseif(!empty($vars['list']->getAttribute('grid-item'))) {
    // Default items.
    $vars['list_item_classes'][] = $vars['list']->getAttribute('grid-item');
  }
}