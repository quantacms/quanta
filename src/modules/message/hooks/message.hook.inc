<?php
namespace Quanta\Common;

/**
 * Implements hook_page_after_build().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function message_page_after_build(Environment $env, array $vars) {
  $messages = $env->getData('message');
  // Record log-type messages in the application log.
  if (!empty($messages)) {
    foreach ($messages as $message) {
      if ($message->type == \Quanta\Common\Message::MESSAGE_TYPE_LOG) {
        $username = UserFactory::current($env)->name;
        Logger::get('message')->log(
          Message::logLevel($message->severity),
          str_replace('!username', $username, $message->body),
          array('module' => $message->module, 'key' => $message->key, 'username' => $username)
        );
      }
    }
  }
}

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   An array of variables.
 */
function message_load_includes(Environment $env, array $vars) {
  $module_path = $env->getModulePath('message');
  $env->addInclude($module_path . '/assets/css/message.css');
}
