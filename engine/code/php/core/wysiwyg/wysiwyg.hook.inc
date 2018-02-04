<?php
/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function wysiwyg_load_includes($env, $vars) {
  $env->addInclude('engine/code/php/core/wysiwyg/froala/css/froala_style.min.css');
  $env->addInclude('engine/code/php/core/wysiwyg/froala/css/froala_editor.min.css');
  $env->addInclude('engine/code/php/core/wysiwyg/froala/js/froala_editor.min.js');
  $env->addInclude('engine/code/php/core/wysiwyg/froala/plugins/paragraph_style.min.js');
  $env->addInclude('engine/code/php/core/wysiwyg/froala/plugins/paragraph_format.min.js');
  $env->addInclude('engine/code/php/core/wysiwyg/froala/plugins/link.min.js');
  $env->addInclude('engine/code/php/core/wysiwyg/froala/plugins/lists.min.js');
  $env->addInclude('engine/code/php/core/wysiwyg/froala/plugins/table.css');
  $env->addInclude('engine/code/php/core/wysiwyg/froala/plugins/table.min.js');
  $env->addInclude('engine/code/php/core/wysiwyg/froala/plugins/align.min.js');
  $env->addInclude('engine/code/php/core/wysiwyg/froala/plugins/line_breaker.css');
  $env->addInclude('engine/code/php/core/wysiwyg/froala/plugins/line_breaker.min.js');
  $env->addInclude('engine/code/php/core/wysiwyg/froala/plugins/url.min.js');
  $env->addInclude('engine/code/php/core/wysiwyg/froala/js/froala-quanta.js');
  $env->addInclude('engine/code/php/core/wysiwyg/css/wysiwyg.css');  
}

/**
 * Implements hook_metadata().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function wysiwyg_metadata($env, $vars) {
  // Include the codemirror lib.
  $vars['page']->addData('metadata', array(
    '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.3.0/codemirror.min.css">',
  ));
} 