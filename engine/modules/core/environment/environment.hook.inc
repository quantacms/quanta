<?php
/**
 * Implements hook_boot().
 * Add quanta's basic includes (JS / CSS files)
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function environment_boot($env, $vars) {
  $env->addInclude('engine/modules/core/engine/css/engine.css');
  $env->addInclude('engine/modules/core/engine/js/jquery.min.js');
  $env->addInclude('engine/modules/core/engine/js/jquery-ui.min.js');
  $env->addInclude('engine/modules/core/engine/js/jquery.ui.widget.js');
  $env->addInclude('engine/modules/core/engine/js/zebra.js');
  $env->addInclude('engine/modules/core/engine/css/zebra.css');
  $env->addInclude('engine/modules/core/engine/js/engine.js');
  // TODO: determine when to run cron.
  if (isset($_GET['cron'])) {
    $env->hook('cron');
  }
}

/**
 * Implements hook_complete().
 * Run the Garbage collector.
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function environment_complete($env, $vars) {
  $garbage = new Garbage($env);
  $garbage->collect();
}
