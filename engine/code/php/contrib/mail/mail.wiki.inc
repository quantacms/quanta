<?php

/**
 * Implements hook_cron().
 * @param $vars
 */
function wiki_MAILFORM($replace, $attributes, $form_id, $string, $page) {
  $inputs = array_flip(array('to', 'from', 'from_name', 'to_name'));

  if (isset($_REQUEST['to'])) {
    $queue = new Node($page->env, '_mailqueue');
    // TODO: better way to create unique id for mail.
    $mail = new Mail($page->env, time() . '_' . rand(10000, 20000), $queue);
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
    return str_replace($replace, '<b>Your email has been sent. Thank you!</b>', $string);
  }
  else {
  // Add mail attributes
  foreach ($attributes as $k => $val) {
    if (isset($inputs[$k])) {
      wiki_INPUT($replace, array('type' => 'hidden', 'name' => $k, 'value'=> $attributes[$k]), $form_id, $string, $page);
    }
  }
    $form = wiki_FORM($replace, $attributes, $form_id, $string, $page);
    return $form;
  }
}