<?php
namespace Quanta\Common;

/**
 * Implements qtag MESSAGES.
 *
 * Renders active system messages (errors, warnings, etc.).
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
function qtag_MESSAGES($env, $target, &$attributes) {
  return Message::burnMessages(MESSAGE_TYPE_SCREEN);
}