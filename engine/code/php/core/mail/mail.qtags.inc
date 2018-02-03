<?php

/**
 * Implements qtag MAILFORM.
 *
 * A mail / contact form.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_MAILFORM($env, $target, $attributes) {
  if (!isset($attributes['ok_message'])) {
    $attributes['ok_message'] = 'Your mail has been sent. Thanks!';
  }
  $attributes['type'] = 'mailform';
  $form_data = qtag_FORM($env, $target, $attributes);
  return $form_data;
}