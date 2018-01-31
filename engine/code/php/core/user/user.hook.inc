<?php

/**
 * Implements hook_boot();
 * @param $vars
 */
function user_boot($vars) {
  $vars['env']->sysdir('users', '_users');
  $vars['env']->sysdir('roles', '_roles');
}

/**
 * Implements hook_load_includes().
 *
 * @param $vars
 *   Miscellaneous environment / page variables.
 */
function user_load_includes($vars) {
  /** @var Environment $env */
  $env = $vars['env'];
  $env->addInclude('engine/code/php/core/user/js/user.js');
  $env->addInclude('engine/code/php/core/user/css/user.css');
}

/**
 * Main setup of users.
 * @param $vars
 */
function user_setup($vars) {
  // Create the basic roles.
  $basic_roles = array(
    'anonymous' => array('title' => 'Anonymous'),
    'logged' => array('title' => 'Logged-in User'),
    'admin' => array('title' => 'Admin'),
  );
  foreach ($basic_roles as $role => $roledata) {
    if (!is_dir($vars['env']->dir['roles'] . '/' . $role)) {
      NodeFactory::buildNode($vars['env'], $role, '_roles', $roledata);
    }
  }
}

/**
 * Implements hook_body_classes.
 * @param $vars
 */
function user_body_classes($vars) {
  $user = UserFactory::current($vars['env']);
  $roles = $user->getRoles();
  foreach ($roles as $role) {
    $vars['page']->addData('body_classes', array('user-' . trim($role)));
  }
}

/**
 * Implementation of hook_action_login.
 * There is a node delete request.
 * @param $vars
 */
function user_action_login($vars) {

  $tmp_user = new User($vars['env'], array_pop($vars['data']['username']));
  $login = $tmp_user->logIn(array_pop($vars['data']['password']));
  exit($login);
}

/**
 * Implements hook_shadow_user_register_form.
 * @param $vars
 */
function user_shadow_user_register_form($vars) {
  $user = UserFactory::current($vars['env']);
  print "CONTEXT " . $vars['env']->getContext();
  $tab = $user->renderEditForm($vars['env']->getContext());
  $vars['shadow']->addTab('Insert your data', $tab, 1);
  $vars['shadow']->addButton('edit-save', '<span style="color:green">&check;&nbsp;</span> Sign Up!');
}

/**
 * Implements hook_shadow_user_edit_form.
 */
function user_shadow_user_edit_form($vars) {
  /** @var Shadow $shadow */
  $shadow = $vars['shadow'];
  /** @var Environment $env */
  $env = $vars['env'];

  $curr_user = UserFactory::current($env);
  $user = new User($env, $curr_user->name);

  $tab = $user->renderEditForm($shadow->getContext());
  $shadow->addTab('Your Profile', $tab, 1);
  $shadow->addButton('edit-save', '<span style="color:green">&check;&nbsp;</span> Update');
}

/**
 * Implements hook_shadow_user_login_form.
 */
function user_shadow_user_login_form($vars) {
  /** @var Environment $env */
  $env = $vars['env'];
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
 * @param array $vars
 */
function user_action_user_edit($vars) {
  /** @var Environment $env */
  $env = $vars['env'];
  $response_json = UserFactory::requestAction($env, $vars['data']['action'], $vars['data']);
  exit($response_json);
}

/**
 * Implements hook_action_user_register.
 * There is an user registration request.
 *
 * @param array $vars
 */
function user_action_user_register($vars) {
  $response_json = UserFactory::requestAction($vars['env'], $vars['data']['action'], $vars['data']);
  exit($response_json);
}

/**
 * Implements hook_action_logout.
 * There is a node delete request.
 * @param $vars
 */
function user_action_logout($vars) {
  $user = UserFactory::current($vars['env']);
  exit($user->logOut());
}
