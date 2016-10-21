<?php
/**
 * @param $vars
 */
function ckeditor_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/ckeditor/js/ckeditor.js');
  $vars['env']->addInclude('engine/code/php/core/ckeditor/js/ckeditor-quanta.js');
}