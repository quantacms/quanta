<?php

/**
 * Implements hook_cron().
 * @param $vars
 */
function mail_init($vars) {
  $mail_tmp = $vars['env']->dir['docroot'] . '/_mailqueue';
  $vars['env']->sysdir($mail_tmp);
  // Get the mails to queue.
  print $mail_tmp;
  $mail_nodes = ($vars['env']->scanDirectory($mail_tmp, array(
    'exclude_dirs' => DIR_INACTIVE,
    'type' => DIR_DIRS,
    )));

  foreach ($mail_nodes as $k) {
    $mail_node = new Mail($vars['env'], $k);
    $mail_node->send();
  }
}

/**
 * Submitting a mail form.
 * @param $vars
 */
function mail_mailform_form_submit($vars) {
  $mail_tmp = $vars['env']->dir['docroot'] . '/_mailqueue';
  $vars['env']->sysdir($mail_tmp);

  $queue = new Node($vars['env'], '_mailqueue');
  // TODO: better way to create unique id for mail.
  $mail = new Mail($vars['env'], time() . '_' . rand(10000, 99999), $queue);
  $body = '-----<br />';
  // TODO: Check that form field exists. Validate. Security. XSS. Everything.
  foreach ($_REQUEST as $k => $v) {
    if (isset($inputs[$k])) {
      $mail->setData($k, $v);
    } else {
      $body .= $k .': ' . $v . '<br/>';
    }
  }
  $mail->setTitle('New email received from ' . $_SERVER['HTTP_HOST']);
  $mail->setContent($body);
  $mail->save();
  // TODO: very bad. No way to send mail?
  $vars['env']->hook('cron');
}

