<?php
namespace Quanta\Common;

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
  $env->addInclude($module_path . '/css/utilities.css');
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
  } else {
    $page->addCSS($module_path . '/css/no_grid.css');
  }
}


/**
 * Implements hook_qtag().
 *
 * Renders a Quanta grid.
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function grid_qtag($env, &$vars) {
  /** @var Qtag $qtag */
  $qtag = $vars['qtag'];

  if (!empty($qtag->getData('grid'))) {
    $inner_open = '';
    $inner_close = '';
    // Sets the tag to use for the grid cell.
    // TODO: use ?? PHP7 operator.
    $grid_html_tag = !empty($qtag->getData('grid_html_tag')) ? $qtag->getData('grid_html_tag') : 'div';
    $grid_id = !empty($qtag->getData('grid_id')) ? $qtag->getData('grid_id'): '';
    $grid_classes = $qtag->getData('grid');

    if (!preg_match('~(?<!\S)grid(?!\S)~i', $grid_classes)) {
      // Not a grid container? => grid child: add an inner wrapper to break the "grid" behaviour
      $inner_open = '<div class="inner">';
      $inner_close = '</div>';
    }

    // Generate the grid cell.
    $vars['qtag']->html = '<' . $grid_html_tag . (!empty($grid_id) ? ' id="' . $grid_id . '"' : '') . (!empty($grid_classes) ? ' class="' . $grid_classes . '"' : '') . '>' . $inner_open . $vars['qtag']->html . $inner_close . '</' . $grid_html_tag . '>';
  }
}


/**
 * Adds grid wrappers to a list item, when grid parameters are specified.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param $vars
 *   An array of variables.
 */
function grid_list_item($env, $vars) {
  /** @var ListObject $list */
  $list = $vars['list'];
  
  $is_grouped = !empty($list->getAttribute('grid_list_item_group'));

  if ($is_grouped) {
    $group_counter = $vars['list_item_counter'] % $list->getAttribute('grid_list_item_group');
  }

  if (!empty($list->getAttribute('grid_list_item_' . $vars['list_item_counter']))) {
    // Specific item.
    $vars['list_item_classes'][] = $list->getAttribute('grid_list_item_' . $vars['list_item_counter']);
  }
  elseif($is_grouped && !empty($list->getAttribute('grid_list_item_group_' . $group_counter))) {
    // Specific item in the interval.
    $vars['list_item_classes'][] = $list->getAttribute('grid_list_item_group_' . $group_counter);
  }
  elseif(!empty($list->getAttribute('grid_list_item_odd') && ($vars['list_item_counter'] % 2 == 1))) {
    // Odd items.
    $vars['list_item_classes'][] = $list->getAttribute('grid_list_item_odd');
  }
  elseif(!empty($list->getAttribute('grid_list_item_even') && ($vars['list_item_counter'] % 2 == 0))) {
    // Even items.
    $vars['list_item_classes'][] = $list->getAttribute('grid_list_item_even');
  }
  elseif(!empty($list->getAttribute('grid_list_item'))) {
    // Default items.
    $vars['list_item_classes'][] = $list->getAttribute('grid_list_item');
  }
}