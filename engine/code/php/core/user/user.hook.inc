<?php
/**
 * Implements hook_init.
 */
function user_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/user/js/user.js');
  $vars['env']->addInclude('engine/code/php/core/user/css/user.css');

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
  $tab = $user->renderEditForm($vars['shadow']->getContext());
  $vars['shadow']->addTab('Insert your data', $tab, 1);
  $vars['shadow']->addButton('edit-save', '<span style="color:green">&check;&nbsp;</span> Sign Up!');
}

/**
 * Implements hook_shadow_user_edit_form.
 * @param $vars
 */
function user_shadow_user_edit_form($vars) {
  $curr_user = UserFactory::current($vars['env']);
  $user = new User($vars['env'], $curr_user->name);
  $tab = $user->renderEditForm($vars['shadow']->getContext());
  $vars['shadow']->addTab('Your Profile', $tab, 1);
  $vars['shadow']->addButton('edit-save', '<span style="color:green">&check;&nbsp;</span> Update');
}

/**
 * Implements hook_shadow_user_login_form.
 * @param $vars
 */
function user_shadow_user_login_form($vars) {
  $user = UserFactory::current($vars['env']);
  $tab = $user->renderLoginForm();
  $vars['shadow']->addTab('Your Login Data', $tab, 1);
  $vars['shadow']->addButton('edit-save', '<span style="color:green">&check;&nbsp;</span> Login');

}

/**
 * Implements hook_action_user_edit.
 * There is an user edit request.
 * @param $vars
 */
function user_action_user_edit($vars) {
  $response_json = UserFactory::requestAction($vars['env'], $vars['data']['action'], $vars['data']);
  exit($response_json);
}

/**
 * Implements hook_action_user_register.
 * There is an user registration request.
 * @param $vars
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
