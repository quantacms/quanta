<?php

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   An array of variables.
 */
function social_load_includes($env, $vars) {
  $module_path = $env->getModulePath('social');
  $env->addInclude($module_path . '/css/social.css');
}
