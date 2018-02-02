<?php

/**
 * Implements qtag MAILFORM.
 */
function qtag_MAILFORM($env, $target, $attributes) {
  if (!isset($attributes['ok_message'])) {
    $attributes['ok_message'] = 'Your mail has been sent. Thanks!';
  }
  $attributes['type'] = 'mailform';
  $form_data = qtag_FORM($env, $target, $attributes);
  return $form_data;
}