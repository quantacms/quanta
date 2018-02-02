<?php
/**
 * Implements qtag MESSAGES.
 */
function qtag_MESSAGES($env, $target, $attributes) {
  return Message::burnMessages(MESSAGE_TYPE_SCREEN);
}