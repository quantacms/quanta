<?php
/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param $vars
 *   An array of variables.
 */
function foundation_load_includes($env, $vars) {
  $env->addInclude('engine/code/php/contrib/foundation/js/foundation.min.js');
  $env->addInclude('engine/code/php/contrib/foundation/css/foundation.min.css');
}

/**
 * Render a foundation box.
 *
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param $vars
 *   An array of variables.
 */
function foundation_wikitag($env, $vars) {
  $foundation_attributes = array('large', 'medium', 'small');

  if (!empty($vars['attributes']['columns']) || !empty($vars['attributes']['row'])) {
    $classes = array();
    if (!empty($vars['attributes']['columns'])) {
      $classes[] = 'columns';
    }
    if (!empty($vars['attributes']['row'])) {
      $classes[] = 'row';
    }

    $id = isset($vars['attributes']['id']) ? $vars['attributes']['id'] : 'foundation-block-' . rand(1, 999999);

    foreach($foundation_attributes as $fattr) {
      if (!empty($vars['attributes'][$fattr])) {
        $classes[] = $fattr . '-' . $vars['attributes'][$fattr];
      }
    }
    foreach ($vars['wikitag'] as $tag => $content) {
      $vars['wikitag'][$tag] = '<div id="' . $id . '" class="' . implode(' ', $classes) . '">' . $content . '</div>';
    }
  }
}


/**
 * Render a foundation box.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param $vars
 *   An array of variables.
 */
function foundation_list_item($env, $vars) {
  $foundation_children_attributes = array('child-large', 'child-medium', 'child-small');
  $classes = array();
  foreach ($foundation_children_attributes as $cattr) {
    if (!empty($cattr_value = $vars['list']->getAttribute($cattr))) {
      $classes[] = str_replace('child-', '', $cattr) . '-' . $cattr_value;
    }
  }
  if (!empty($classes)) {
    $vars['list_item'] = '<div class="' . implode(' ', $classes). ' columns">' . $vars['list_item'] . '</div>';
  }
}
