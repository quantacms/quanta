<?php

function user_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/user/js/user.js');
  $vars['env']->addInclude('engine/code/php/core/user/css/user.css');

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

/**
 * Implementation of hook_action_login.
 * There is a node delete request.
 * @param $vars
 */
function user_action_login($vars) {
  $tmp_user = new User($vars['env'], $vars['data']['username']);
  exit($tmp_user->logIn($vars['data']['password']));
}

function user_shadow_user_register_form($vars) {
  $user = User::current($vars['env']);
  $tab = $user->renderEditForm($vars['shadow']->getContext());
  $vars['shadow']->addTab('Insert your data', $tab, 1);
  $vars['shadow']->addButton('edit-save', '<span style="color:green">&check;&nbsp;</span> Sign Up!');
}


function user_shadow_user_edit_form($vars) {
  $curr_user = User::current($vars['env']);
  $user = new User($vars['env'], $curr_user->name);

  $tab = $user->renderEditForm($vars['shadow']->getContext());
  $vars['shadow']->addTab('Your Profile', $tab, 1);
  $vars['shadow']->addButton('edit-save', '<span style="color:green">&check;&nbsp;</span> Update');

}

function user_shadow_user_login_form($vars) {
  $user = User::current($vars['env']);
  $tab = $user->renderLoginForm();
  $vars['shadow']->addTab('Your Login Data', $tab, 1);
  $vars['shadow']->addButton('edit-save', '<span style="color:green">&check;&nbsp;</span> Login');

}

/**
 * Implementation of hook_action_user_edit.
 * There is an user edit request.
 * @param $vars
 */
function user_action_user_edit($vars) {
  $response_json = User::requestAction($vars['env'], $vars['data']['action'], $vars['data']);
  exit($response_json);
}

/**
 * Implementation of hook_action_user_register.
 * There is an user registration request.
 * @param $vars
 */
function user_action_user_register($vars) {
  $response_json = User::requestAction($vars['env'], $vars['data']['action'], $vars['data']);
  exit($response_json);
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