<?php

/**
 * Implements hook_cron().
 * @param $vars
 */
function mail_init($vars) {
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

/**
 * Submitting a mail form.
 * @param $vars
 */
function mail_form_submit($vars) {
  $mail_tmp = $vars['env']->dir['docroot'] . '/_mailqueue';
  $vars['env']->sysdir($mail_tmp);
  $queue = new Node($vars['env'], '_mailqueue');

  // TODO: better way to create unique id for mail.
  $mail = new Mail($vars['env'], time() . '_' . rand(10000, 99999), $queue);
  $body = '';

  $form = $vars['form'];
  $items = $form->getItems();

  foreach ($items as $item) {
    $body .= '<b>' . $item->getLabel() . '</b>: <br>' . $item->getValue() . '<br><br>';
  }

  $vars['env']->hook('mailform_' . $vars['form']->getId() . '_send', array('mail' => &$mail));
  $mail->setBody($body);

  //$mail->save();
  $mail->send();
}

