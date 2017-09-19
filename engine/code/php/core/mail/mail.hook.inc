<?php

/**
 * Implements hook_cron().
 * @param $vars
 */
function mail_init($vars) {
  $vars['env']->sysdir('mailqueue', '_mailqueue');
  // Get the mails to queue.
  // TODO: don't check all emails at every page load. Use CRON instead?
  $mail_nodes = ($vars['env']->scanDirectory($vars['env']->dir['mailqueue'], array(
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
function mail_form_type_mailform_submit($vars) {
  $mail_tmp = $vars['env']->dir['docroot'] . '/_mailqueue';
  $vars['env']->sysdir('mailqueue', $mail_tmp);
  $queue = new Node($vars['env'], '_mailqueue');

  // TODO: better way to create unique id for mail.
  $mail = new Mail($vars['env'], time() . '_' . rand(10000, 99999), $queue);
  $body = '';

  $form = $vars['form'];
  $items = $form->getItems();

  foreach ($items as $item) {

    $value = $item->getValue();

    // Non-html email: convert newlines.
    if (($item->getType() == 'text') && empty($item->getInputAttr('wysiwyg')))  {
      $value = nl2br($value);
    }

    if ($item->getType() != 'submit') {
      $body .= '<b>' . $item->getLabel() . '</b>: <br>' . $value . '<br><br>';
    }
  }

  $vars['env']->hook('mailform_' . $vars['form']->getId() . '_send', array('mail' => &$mail, 'form' => &$form));
  $mail->setBody($body);

  //$mail->save();
  $mail->send();
}

