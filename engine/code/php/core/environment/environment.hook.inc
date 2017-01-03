<?php
/**
 * Implements hook_init().
 * Add basic CSS and JS files.
 * @param $vars
 */
function environment_boot($vars) {
  $vars['env']->addInclude('engine/code/css/engine.css');
  $vars['env']->addInclude('engine/code/js/lib/jquery.js');
  $vars['env']->addInclude('engine/code/js/engine.js');

  $vars['env']->addInclude('engine/code/js/lib/jquery.knob.js');
  $vars['env']->addInclude('engine/code/js/lib/jquery.ui.widget.js');
  $vars['env']->addInclude('engine/code/js/lib/jquery.iframe-transport.js');
  $vars['env']->addInclude('engine/code/js/lib/zebra.js');
  $vars['env']->addInclude('engine/code/css/zebra.css');

  // TODO: determine when to run cron.
  if (isset($_GET['cron'])) {
    $vars['env']->hook('cron');
  }
}