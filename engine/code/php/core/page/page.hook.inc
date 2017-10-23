<?php
/**
 * Implements hook_body_classes.
 */
function page_body_classes($vars) {
  $vars['page']->addData('body_classes', array('page'));
}

/**
 * React to robots.txt request.
 * @param $vars
 */
function page_boot($vars) {
  // Start robots.txt configuration.
  // TODO: object oriented, using templates, etc. etc.
  if ($vars['env']->request[count($vars['env']->request) - 1] == 'robots.txt') {
    $robots = new Robots($vars['env']);
    $robots->disallow('*', '/search');
    // $robots->disallow('*', '/files*');
    // $robots->disallow('*', '/css*');
    // $robots->disallow('*', '/js*');
    header("Content-Type: text/plain");
    print $robots->render();
    exit();
  }
}
