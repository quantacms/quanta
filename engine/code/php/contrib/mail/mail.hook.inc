<?php

/**
 * Implements hook_cron().
 * @param $vars
 */
function mail_cron($vars) {
  $mail_tmp = $vars['env']->dir['docroot'] . '/_mailqueue';
  $vars['env']->sysdir($mail_tmp);
  // Get the mails to queue.
  $mail_nodes = ($vars['env']->scanDirectory($mail_tmp, array(
    'exclude_dirs' => DIR_INACTIVE,
    'type' => DIR_DIRS,
    )));

  foreach ($mail_nodes as $k) {
    $mail_node = new Mail($vars['env'], $k);
    $mail_node->send();
  }
}
