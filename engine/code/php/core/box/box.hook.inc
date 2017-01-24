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
  if (!empty($vars['attributes']['box'])) {
    $boxsize = explode('x', $vars['attributes']['box']);
    $w = empty($boxsize[1]) ? NULL : $boxsize[0];
    $h = empty($boxsize[1]) ? NULL : $boxsize[1];
    $box = new Box($vars['env'], $w, $h, $vars['attributes']);

    foreach ($vars['wikitag'] as $tag => $content) {
      $box->setHtml($content);
      $vars['wikitag'][$tag] = $box->render();
    }
  }
}
