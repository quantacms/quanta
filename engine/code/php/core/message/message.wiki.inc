<?php
/**
 * Wiki hook for messages.
 * [MESSAGES] wiki tag was moved into hook.
 */

function wiki_MESSAGES($tagname, $attribute, $string, $env) {
  return Message::burnMessages(MESSAGE_TYPE_SCREEN);
}