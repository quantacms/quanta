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
function jumper_load_includes($env, $vars) {
  $module_path = $env->getModulePath('jumper');

  $env->addInclude($module_path . '/js/jumper.js');
}
