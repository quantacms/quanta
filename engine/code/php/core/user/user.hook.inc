<?php

/**
 * Implements hook_boot();
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   Miscellaneous environment / page variables.
 */
function user_boot($env, $vars) {
  $env->sysdir('users', '_users');
  $env->sysdir('roles', '_roles');
	//setcookie('user', NULL, $env->getData('session_lifetime', time() - 86400));
}

/**
 * Implements hook_init();
 *
 * Refresh user's session cookie.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   Miscellaneous environment / page variables.
 */
function user_init($env, $vars) {
  $user = UserFactory::current($env);
  
	//setcookie('user', NULL, $env->getData('session_lifetime', 86400));
}

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   Miscellaneous environment / page variables.
 */
function user_load_includes($env, $vars) {
  $env->addInclude('engine/code/php/core/user/js/user.js');
  $env->addInclude('engine/code/php/core/user/css/user.css');
}

/**
 * Implements hook_setup.
 * Main setup of users.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   Miscellaneous environment / page variables.
 */
function user_setup($env, $vars) {
  // Create the basic roles.
  $basic_roles = array(
    'anonymous' => array('title' => 'Anonymous'),
    'logged' => array('title' => 'Logged-in User'),
    'admin' => array('title' => 'Admin'),
  );
  foreach ($basic_roles as $role => $roledata) {
    if (!is_dir($env->dir['roles'] . '/' . $role)) {
      NodeFactory::buildNode($env, $role, '_roles', $roledata);
    }
  }
}

/**
 * Implements hook_body_classes.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function user_body_classes($env, $vars) {
  $user = UserFactory::current($env);
  $roles = $user->getRoles();
  foreach ($roles as $role) {
    $vars['page']->addData('body_classes', array('user-' . trim($role)));
  }
}

/**
 * Implements hook_action_login().
 * There is an user login request.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function user_action_login($env, $vars) {
  // Initialize an user object.
  $tmp_user = new User($env, array_pop($vars['data']['username']));
  // Attempt to log in the user.
  $login = $tmp_user->logIn(array_pop($vars['data']['password']));
  exit($login);
}

/**
 * Implements hook_shadow_user_register_form().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function user_shadow_user_register_form($env, $vars) {
  $user = UserFactory::current($env);
  $tab = '<h2>Create a free account</h2>' . $user->renderEditForm($env->getContext());
  $vars['shadow']->addTab('Insert your data', $tab, 1);
  $vars['shadow']->addButton('edit-save', '<span style="color:green">&check;&nbsp;</span> Sign Up!');
}

/**
 * Implements hook_shadow_user_edit_form().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function user_shadow_user_edit_form($env, $vars) {
  $curr_user = UserFactory::current($env);
  $user = new User($env, $curr_user->name);
  /** @var Shadow $shadow */
  $shadow = $vars['shadow'];
  // TODO: customizable titles.
  switch($env->getContext()) {
    case 'user_register':
      $title = 'Create a free account';
      break;
    case 'user_edit_own':
      $title = 'Edit your profile';
      break;
    case 'user_edit':
      $title = 'Edit user: ' . $user->getName();
      break;
  }

  $tab = '<h2>' . $title . '</h2>' . $user->renderEditForm($env->getContext());
  $shadow->addTab('Your Profile', $tab, 1);
  $shadow->addButton('edit-save', '<span style="color:green">&check;&nbsp;</span> Update');
}

/**
 * Implements hook_shadow_user_login_form.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function user_shadow_user_login_form($env, $vars) {
  $user = UserFactory::current($env);
  $tab = $user->renderLoginForm();
  /** @var Shadow $shadow */
  $shadow = $vars['shadow'];
  $shadow->addTab('Your Login Data', $tab, 1);
  $shadow->addButton('edit-save', '<span style="color:green">&check;&nbsp;</span> Login');
}

/**
 * Implements hook_action_user_edit.
 * There is an user edit request.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function user_action_user_edit($env, $vars) {
  $response_json = UserFactory::requestAction($env, $vars['data']['action'], $vars['data']);
  exit($response_json);
}

/**
 * Implements hook_action_user_edit_own.
 * There is an user self edit request.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function user_action_user_edit_own($env, $vars) {
  return user_action_user_edit($env, $vars);
}

/**
 * Implements hook_action_user_register().
 * There is an user registration request.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function user_action_user_register($env, $vars) {
  $response_json = UserFactory::requestAction($env, $vars['data']['action'], $vars['data']);
  exit($response_json);
}

/**
 * Implements hook_action_logout().
 *
 * There is an user logout request.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function user_action_logout($env, $vars) {
  $user = UserFactory::current($env);
  exit($user->logOut());
}
