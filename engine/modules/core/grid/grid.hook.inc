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
  $env->addInclude($module_path . '/css/grid_all.css');

}


/**
 * Implements hook_page_init().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function grid_page_init($env, $vars) {
  /** @var Page $page */
  $page = $vars['page'];

  $module_path = $env->getModulePath('grid');

  if (supportsGrid()) {
    $page->addCSS($module_path . '/css/grid.css');
  }
  else {
    $page->addCSS($module_path . '/css/no_grid.css');
  }

  $page->addCSS($module_path . '/css/utilities.css');
}


/**
 * Renders a Quanta grid.
 *
 * 
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function grid_qtag($env, $vars) {
  if (!empty($vars['attributes']['grid'])) {
    $grid_html_tag = !empty($vars['attributes']['grid_html_tag']) ? $vars['attributes']['grid_html_tag'] : 'div';
    $grid_id = !empty($vars['attributes']['grid_id']) ? $vars['attributes']['grid_id'] : '';
    $classes = (!empty($vars['attributes']['grid']) && $vars['attributes']['grid'] != 1) ? $vars['attributes']['grid'] : '';

    $vars['qtag'] = '<' . $grid_html_tag . (!empty($grid_id) ? ' id="' . $grid_id . '"' : '') . ' class="grid ' . $classes . '">' . $vars['qtag'] . '</' . $grid_html_tag . '>';
  }
}



function grid_list_item($env, $vars) {
  $is_grouped = !empty($vars['list']->getAttribute('grid_list_item_group'));

  if ($is_grouped) {
    $group_counter = $vars['list_item_counter'] % $vars['list']->getAttribute('grid_list_item_group');
  }

  if (!empty($vars['list']->getAttribute('grid_list_item_' . $vars['list_item_counter']))) {
    // Specific item.
    $vars['list_item_classes'][] = $vars['list']->getAttribute('grid_list_item_' . $vars['list_item_counter']);
  } elseif($is_grouped && !empty($vars['list']->getAttribute('grid_list_item_group_' . $group_counter))) {
    // Specific item in the interval.
    $vars['list_item_classes'][] = $vars['list']->getAttribute('grid_list_item_group_' . $group_counter);
  } elseif(!empty($vars['list']->getAttribute('grid_list_item_odd') && ($vars['list_item_counter'] % 2 == 1))) {
    // Odd items.
    $vars['list_item_classes'][] = $vars['list']->getAttribute('grid_list_item_odd');
  } elseif(!empty($vars['list']->getAttribute('grid_list_item_even') && ($vars['list_item_counter'] % 2 == 0))) {
    // Even items.
    $vars['list_item_classes'][] = $vars['list']->getAttribute('grid_list_item_even');
  } elseif(!empty($vars['list']->getAttribute('grid_list_item'))) {
    // Default items.
    $vars['list_item_classes'][] = $vars['list']->getAttribute('grid_list_item');
  }
}