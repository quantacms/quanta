<?php

function message_complete($vars) {
    $messages = $vars['env']->getItems('message');
  foreach ($messages as $message) {
    if ($message->type == MESSAGE_TYPE_LOG) {
    $username = User::current($vars['env'])->name;
      $fh = fopen($vars['env']->dir['log'] . '/log.txt', 'a+') or die("error");
      fwrite($fh, '[' . date('Y-m-d h:i:s') . '] ' . str_replace('!username', $username, $message->body) . '
');
      fclose($fh);
    }
  }
}