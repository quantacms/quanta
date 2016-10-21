<?php

/**
 * Implements hook_cron().
 * @param $vars
 */
function wiki_MAILFORM($attributes, $form_id, $string, $env) {
  $form_id = 'mailform';
  $inputs = array_flip(array('to', 'from', 'from_name', 'to_name'));

  // Add mail attributes
  foreach ($attributes as $k => $val) {
    if (isset($inputs[$k])) {
      wiki_INPUT(array(
        'type' => 'hidden',
        'name' => $k,
        'value' => $attributes[$k]
      ), $form_id, $string, $env);
    }
  }

  $attributes['ok_message'] = 'Your mail has been sent. Thanks!';
  $form = wiki_FORM($attributes, $form_id, $string, $env);
  return $form;
}