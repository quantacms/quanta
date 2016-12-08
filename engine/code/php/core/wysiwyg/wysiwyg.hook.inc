<?php
/**
 * @param $vars
 */
function wysiwyg_init($vars) {
  $vars['env']->addInclude('/cdnjs.cloudflare.com/ajax/libs/font-awesome/4.4.0/css/font-awesome.min.css');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/css/froala_style.min.css');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/css/froala_editor.min.css');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/js/froala_editor.min.js');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/plugins/paragraph_style.min.js');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/plugins/paragraph_format.min.js');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/plugins/table.css');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/plugins/table.min.js');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/plugins/align.min.js');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/plugins/line_breaker.css');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/plugins/line_breaker.min.js');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/plugins/url.min.js');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/js/froala-quanta.js');
}