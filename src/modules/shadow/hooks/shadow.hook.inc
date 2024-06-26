<?php
namespace Quanta\Common;

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   Miscellaneous environment / page variables.
 */
function shadow_load_includes(Environment $env, array $vars) {
  $module_path = $env->getModulePath('shadow');

  $env->addInclude($module_path . '/assets/js/shadow.js');
  $env->addInclude($module_path . '/assets/css/shadow.css');
}

/**
 * Implements hook_init().
 * There is the request to open a Shadow overlay form.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function shadow_boot(Environment $env, $vars) {
  // Check if there is a request to open a Shadow popup.
  if (isset($_REQUEST['shadow'])) {
    $shadow_json = json_decode($_REQUEST['shadow'], true);
    // Initialize the Shadow popup.
    $shadow = new Shadow($env, $shadow_json);
    // Check the requested context.
    $env->setContext($shadow_json['context']);
    $env->setData('shadow', $shadow);
    $env->setData('page', $shadow);
  }
}

/**
 * Implements hook_init().
 *
 * There is the request to open a Shadow overlay form.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function shadow_init(Environment $env, array $vars) {
  /** @var Shadow $shadow */
  $shadow = $env->getData('shadow');
  if (!empty($env->getData('shadow'))) {
    $shadow->loadComponents();
    // Render the Shadow popup.
    print $shadow->render();
    exit();
  }
}
