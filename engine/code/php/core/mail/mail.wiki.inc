<?php

/**
 * Implements hook_cron().
 * @param $attributes
 * @param $form_id
 * @param $string
 * @param $env
 * @return mixed
 * @internal param $vars
 */
function wiki_MAILFORM($attributes, $form_id, $string, $env) {
  if (!isset($attributes['ok_message'])) {
    $attributes['ok_message'] = 'Your mail has been sent. Thanks!';
  }
  $attributes['type'] = 'mailform';
  $form_data = wiki_FORM($attributes, $form_id, $string, $env);
  return $form_data;
}