<?php
namespace Quanta\Common;

/**
 * Implements hook_page_init().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   Mixed variables.
 */
function meta_page_init(Environment $env, array $vars) {
  $metadata = &$vars['metadata'];
  // Page metadata.
  $env->hook('metadata', $vars);

  $env->setData('metadata', $metadata);

}
