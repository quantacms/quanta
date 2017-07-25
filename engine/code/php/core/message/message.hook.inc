<?php

/**
 * Implements hook_page_after_build().
 * @param $vars
 */
function message_page_after_build($vars) {
    $messages = $vars['env']->getItems('message');
  foreach ($messages as $message) {
    if ($message->type == MESSAGE_TYPE_LOG) {
    $username = UserFactory::current($vars['env'])->name;
      $fh = fopen($vars['env']->dir['log'] . '/log.txt', 'a+') or die("error");
      fwrite($fh, '[' . date('Y-m-d h:i:s') . '] ' . str_replace('!username', $username, $message->body) . '
');
      fclose($fh);
    }
  }
}

/**
 * Implements hook_init().
 * @param $vars
 */
function message_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/message/css/message.css');
}
