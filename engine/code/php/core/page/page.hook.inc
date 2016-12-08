<?php
/**
 * Implements hook_body_classes.
 */
function page_body_classes($vars) {
  $vars['page']->addBodyClass('page');
}

function page_init($vars) {
  if ($vars['env']->getRequestedPath() == '403') {
    $vars['env']->setData('content', file_get_contents('core/page/tpl/403.inc'));
  }
}

function page_boot($vars) {
  // Request the page's CSS alsggregated file.
 if ($vars['env']->getRequestedPath() == 'css') {
    header("Content-type: text/css", true);
    $file = $vars['env']->dir['tmp_files'] . '/' . $_GET['D'] . '.css';
    print file_get_contents($file);
    unlink($file);
    die();
  }
  // Request the page's JS aggregated file.
  elseif ($vars['env']->getRequestedPath() == 'js') {
    $file = $vars['env']->dir['tmp_files'] . '/' . $_GET['D'] . '.js';
    print file_get_contents($file);
    unlink($file);
    die();
  }
}

