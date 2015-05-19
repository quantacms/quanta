<?php
/**
 * Implementation of hook_init
 * Check login and logout requests.
 * @param $vars
 */
function user_init($vars) {
  // We have a login request.
  $user = User::current($vars['env']);
  if (isset($_REQUEST['action']) && ($_REQUEST['action'] == 'login')) {
    $tmp_user = new User($vars['env'], $_REQUEST['username']);
    $tmp_user->logIn(pwd($_REQUEST['password']));
  }

  // We have a logout request.
  if (isset($_REQUEST['action']) && ($_REQUEST['action'] == 'logout')) {
    $user->logOut();
  }
}

/**
 * Implements hook_body_classes.
 */
function user_body_classes($vars) {
  $user = User::current($vars['env']);
  foreach ($user->roles as $role) {
    $vars['page']->addBodyClass('user-' . trim($role));
  }
}
