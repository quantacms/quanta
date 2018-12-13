<?php
namespace Quanta\Common;

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function jumper_load_includes(Environment $env, array $vars) {
  $module_path = $env->getModulePath('jumper');

  $env->addInclude($module_path . '/assets/js/jumper.js');
}
