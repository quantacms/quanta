<?php
/**
 * Implements hook_body_classes.
 */
function user_body_classes($vars) {
  $user = User::current($vars['env']);
  foreach ($user->roles as $role) {
    $vars['page']->addBodyClass('user-' . trim($role));
  }
}

/**
 * Implementation of hook_action_login.
 * There is a node delete request.
 * @param $vars
 */
function user_action_login($vars) {
  $tmp_user = new User($vars['env'], $vars['data']['username']);
  exit($tmp_user->logIn($vars['data']['password']));
}

/**
 * Implementation of hook_action_logout.
 * There is a node delete request.
 * @param $vars
 */
function user_action_logout($vars) {
  $user = User::current($vars['env']);
  exit($user->logOut());
}