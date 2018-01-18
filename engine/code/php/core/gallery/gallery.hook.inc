<?php
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 10/05/15
 * Time: 09:57
 * @param $vars
 */

function gallery_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/gallery/js/gallery.js');
  $vars['env']->addInclude('engine/code/php/core/gallery/css/gallery.css');
}
