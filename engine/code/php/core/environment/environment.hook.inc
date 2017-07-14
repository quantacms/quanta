<?php
/**
 * Implements hook_init().
 * Add basic CSS and JS files.
 * @param $vars
 */
function environment_boot($vars) {
  // Request the page's CSS alsggregated file.
  if ($vars['env']->getRequestedPath() == 'css') {
    header("Content-type: text/css", TRUE);
    $file = $vars['env']->dir['tmp_files'] . '/' . $_GET['D'] . '.css';
    print file_get_contents($file);
    if (unlink($file)) {
      die();
    } else {
      new Message($vars['env'], 'Error: CSS - TMP filee could not be deleted.', MESSAGE_ERROR, MESSAGE_TYPE_SCREEN);
    }
  }
  // Request the page's JS aggregated file.
  elseif ($vars['env']->getRequestedPath() == 'js') {
    $file = $vars['env']->dir['tmp_files'] . '/' . $_GET['D'] . '.js';
    print file_get_contents($file);
    if (unlink($file)) {
      die();
    } else {
      new Message($vars['env'], 'Error: JS - TMP filee could not be deleted.', MESSAGE_ERROR, MESSAGE_TYPE_SCREEN);
    }
  }
  else {
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
}