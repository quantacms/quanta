<?php

/**
 * Implements hook_page_init().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   Mixed variables.
 */
function meta_page_init($env, $vars) {
  $metadata = &$vars['metadata'];
  // Page metadata.
  $env->hook('metadata', $vars);

  $env->setData('metadata', $metadata);

}
