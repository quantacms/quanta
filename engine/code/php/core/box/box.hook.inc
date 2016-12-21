<?php
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 10/05/15
 * Time: 09:57
 */

function box_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/box/js/box.js');
  $vars['env']->addInclude('engine/code/php/core/box/css/box.css');
}

/**
 * Render a box.
 */
function box_wikitag($vars) {
// TODO: move all this logic in a Box class.
  if (!empty($vars['attributes']['box'])) {
    $styles = array();
    $classes = array();
    $boxsize = explode('x', $vars['attributes']['box']);

    if ($boxsize[0] == 'auto') {
      $w = 'auto';
      $class = 'w-auto';
    }
    elseif ($boxsize[0] == 'full') {
      $w = 'auto';
      $class = 'w-full';
    }
    elseif ($boxsize[0] > 0) {
      $w = $boxsize[0] . '%';
      $class = 'w-' . $boxsize[0];
    }
    else {
      // TODO: wrong box size.
    }

    $classes[] = $class;

    if ($boxsize[1] == 'auto') {
      $h = '';
    }
    elseif ($boxsize[1] == 'full') {
      $h = 'h-full';
    }
    elseif ($boxsize[1] > 0) {
      $h = 'h' . $boxsize[1];
    }
    else {
      // TODO: wrong box size.
    }

    $classes[] = $h;
    $name = isset($vars['attributes']['name']) ? $vars['attributes']['name'] : 'box-noname-' . rand(10000000, 99999999);

    $id = isset($vars['attributes']['id']) ? $vars['attributes']['id'] : $name;
    if (isset($vars['attributes']['class'])) {
      $classes[] = $vars['attributes']['class'];
    }
    $classes[] = (!empty($vars['attributes']['clear']) ? 'clear' : '');
    $classes[] = (!empty($vars['attributes']['centered']) ? 'centered' : '');

    $classes[] = 'box-' . $name;

    if (isset($vars['attributes']['margin'])) {
      $styles[] = 'margin:' . $vars['attributes']['margin'] . 'px';
    }
    if (isset($vars['attributes']['padding'])) {
      $styles[] = 'padding:' . $vars['attributes']['padding'] . 'px';
    }
    if (isset($vars['attributes']['border'])) {
      $styles[] = 'border:' . $vars['attributes']['border'] . 'px solid';
    }

    foreach ($vars['wikitag'] as $tag => $content) {

      // Don't create a box for empty content.
      if (empty($content)) {
        continue;
      }

      // TODO: move in tpl file.
      if (!empty($vars['attributes']['title'])) {
        $content = '<h2 class="box-title">' . $vars['attributes']['title'] . '</h2>' . $content;
      }
      $content = '<div class="inner" style="' . implode(';', $styles) . '" >' . $content . '</div>';

      $vars['wikitag'][$tag] = '<div id="' . $id . '" class="box ' . implode(' ', $classes) . '" style="width:' . $w . '">' . $content . '</div>';
    }
  }
}
