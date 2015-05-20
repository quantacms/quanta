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
  $messages = $_SESSION['messages'];
  $output = '';
  foreach($messages as $k => $mess) {
    $message = unserialize($mess);
    if ($message->type == $type) {
      $output .= '<div class="message message-severity-' . $message->severity . '">' . $message->body . '</div>';
      unset($_SESSION['messages'][$k]);
    }
  }
  $string = str_replace($replace, $output, $string);
  return $string;
}