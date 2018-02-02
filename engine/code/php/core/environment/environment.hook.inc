<?php
/**
 * Implements hook_boot().
 * Add quanta's basic includes (JS / CSS files)
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param $vars
 *   An array of variables.
 */
function environment_boot($env, $vars) {

    $env->addInclude('engine/code/css/engine.css');
    $env->addInclude('engine/code/js/lib/jquery.js');
    $env->addInclude('engine/code/js/engine.js');
    $env->addInclude('engine/code/js/lib/jquery.knob.js');
    $env->addInclude('engine/code/js/lib/jquery.ui.widget.js');
    $env->addInclude('engine/code/js/lib/jquery.iframe-transport.js');
    $env->addInclude('engine/code/js/lib/zebra.js');
    $env->addInclude('engine/code/css/zebra.css');

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
 *
 * @param $vars
 *   An array of variables.
 */
function environment_complete($env, $vars) {
  $garbage = new Garbage($env);
  $garbage->collect();
}
