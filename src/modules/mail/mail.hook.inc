<?php
namespace Quanta\Common;
/**
 * Implements hook_init().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   An array of variables.
 */
function mail_init($env, $vars) {
  $env->sysdir('mailqueue', '_mailqueue');
  // Get the mails to queue.
  // TODO: don't check all emails at every page load. Use CRON instead?
  $mail_nodes = ($env->scanDirectory($env->dir['mailqueue'], array(
    'exclude_dirs' => DIR_INACTIVE,
    'type' => DIR_DIRS,
    )));

  foreach ($mail_nodes as $k) {
    $mail_node = new Mail($env, $k);
    $mail_node->send();
  }
}

/**
 * Implements hook_form_type_FORMID_submit().
 * Submitting a mail form.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   An array of variables.
 */
function mail_form_type_mailform_submit($env, $vars) {
  $mail_tmp = $env->dir['docroot'] . '/_mailqueue';
  $env->sysdir('mailqueue', $mail_tmp);
  $queue = new Node($env, '_mailqueue');

  // TODO: better way to create unique id for mail.
  $mail = new Mail($env, time() . '_' . rand(10000, 99999), $queue->getName());
  $body = '';

  $form = $vars['form'];
  $items = $form->getItems();

  foreach ($items as $item) {
    /** @var FormItem $item */
    $value = $item->getValue()[0];

    // Non-html email: convert newlines.
    if (($item->getType() == 'text') && empty($item->getInputAttr('wysiwyg')))  {
      $value = nl2br($value);
    }
    // Submit all the values except the submit button.
    if ($item->getType() != 'submit') {
      $body .= '<b>' . $item->getLabel() . '</b>: <br>' . $value . '<br><br>';
    }
  }
  // Run all mailform hooks to elaborate the form.
  $vars = array('mail' => &$mail, 'form' => &$form);
  $env->hook('mailform_' . $vars['form']->getId() . '_send', $vars);
  $mail->setBody($body);
  //$mail->save();
  $mail->send();
}

