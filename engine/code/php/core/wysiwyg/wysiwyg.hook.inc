<?php
/**
 * Implements hook_load_includes().
 *
 * @param $vars
 *   Miscellaneous environment / page variables.
 */
function wysiwyg_load_includes($vars) {
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/css/froala_style.min.css');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/css/froala_editor.min.css');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/js/froala_editor.min.js');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/plugins/paragraph_style.min.js');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/plugins/paragraph_format.min.js');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/plugins/link.min.js');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/plugins/lists.min.js');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/plugins/table.css');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/plugins/table.min.js');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/plugins/align.min.js');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/plugins/line_breaker.css');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/plugins/line_breaker.min.js');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/plugins/url.min.js');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/froala/js/froala-quanta.js');
  $vars['env']->addInclude('engine/code/php/core/wysiwyg/css/wysiwyg.css');  
}



function wysiwyg_metadata($vars) {
  // Setup body classes per each item in the current node lineage.
  $vars['page']->addData('metadata', array(
    //'<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.4.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />',
    '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.3.0/codemirror.min.css">',
  ));
} 