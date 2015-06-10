<?php
/**
 * Wiki hook for messages.
 * @param $replace
 * @param $tag
 * @param $var
 * @param $string
 * @param Page $page
 * @return mixed
 */
function wiki_MESSAGES($replace, $attributes, $var, $string, $page) {
  $type = isset($attributes[1]) ? $attributes[1] : MESSAGE_TYPE_SCREEN;
  $output = Message::burnMessages($type);

  $string = str_replace($replace, $output, $string);
  return $string;
}