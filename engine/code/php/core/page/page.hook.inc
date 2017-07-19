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
  // TODO: object oriented, using templates, etc. etc.
  if ($vars['env']->request[count($vars['env']->request) - 1] == 'robots.txt') {
    header("Content-Type: text/plain");
    $user_agents = array('*' => array());
    $user_agents['*'][] = 'Disallow: /search';
    $user_agents['*'][] = 'Disallow: /files*';
    $user_agents['*'][] = 'Disallow: /css*';
    $user_agents['*'][] = 'Disallow: /js*';

    foreach ($user_agents as $agent => $directives) {
      echo ('User-agent: ' . $agent . "\n");
      echo (implode("\n", $directives));

    }
    exit();
  }
}
