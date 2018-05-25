<?php
/**
 * Implements hook_boot().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param $vars
 *   An array of variables.
 */
function feeds_boot($env, $vars) {
  if ($env->request[1] == 'feeds') {
    $feed = new Feed($env, $env->request[2]);
    print $feed->render();
    exit;
  }
}