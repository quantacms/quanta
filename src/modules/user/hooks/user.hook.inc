<?php
namespace Quanta\Common;

/**
 * Implements hook_boot();
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   Miscellaneous environment / page variables.
 */
function user_boot(Environment $env, array $vars) {
  // Create system directories for users and roles.
  $env->sysdir('users', '_users');
  $env->sysdir('roles', '_roles');
}

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   Miscellaneous environment / page variables.
 */
function user_load_includes(Environment $env, array $vars) {
  $module_path = $env->getModulePath('user');
  $env->addInclude($module_path . '/assets/js/user.js');
}

/**
 * Implements hook_doctor_setup().
 * Main setup of users.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   Miscellaneous environment / page variables.
 */
function user_doctor_setup(Environment $env, array $vars) {
  /** @var Doctor $doctor */
  $doctor = $vars['doctor'];

  $doctor->op('Creating basic roles');

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
  $doctor->op('Looking for Admin user');
  $adminuser = UserFactory::load($env, 'administrator');
  if (!($adminuser->exists)) {
    $doctor->talk('Not found! Attempting to create Admin User...');
    $password = 'pass';
    $password_repeat = 'pass_repeat';
    // Get admin password from command line...
    if (!empty($doctor->getData('admin-pass'))) {
      $password = $doctor->getData('admin-pass');
      $password_repeat = $doctor->getData('admin-pass');
      $doctor->ok('Pre-selecting admin password');
    }
    // ...or from user input.
    while ($password != $password_repeat) {
      $password = $doctor->ask('Please enter a password for the administrator user', TRUE);
      $password_repeat = $doctor->ask('Please repeat your password', TRUE);
      if ($password != $password_repeat) {
        $doctor->ko('The two passwords are not matching. Please try again!');
      }
    }
    UserFactory::buildUser($env, 'administrator', array(
      'title' => 'Administrator',
      'password' => $password,
      'roles' => array('admin'),
      'email' => 'admin@changeme.com',
      'first_name' => 'John',
      'last_name' => 'Doe',
      'language' => \Quanta\Common\Localization::LANGUAGE_NEUTRAL,
    ));
    $doctor->ok('Done!');
  }
  else {
    $doctor->ok('Found!');
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
function user_body_classes(Environment $env, array $vars) {
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
function user_action_login(Environment $env, array $vars) {
  $username = array_pop($vars['data']['username']);
  // We allow also using email for logging in.
  if (\Quanta\Common\Api::valid_email($username)) {
    $username = UserFactory::getUserFromField($env, 'email', $username);
  }

  // Initialize an user object.
  $tmp_user = new User($env, $username);
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
function user_shadow_user_register_form(Environment $env, array $vars) {
  $tab = '<h2>Insert your data</h2>' . UserFactory::renderUserEditForm($env, $env->getContext());
  $vars['shadow']->addTab('Insert your data', $tab, 1);
  $vars['shadow']->addButton('edit-save', t('Sign Up!'));
}

/**
 * Implements hook_shadow_user_edit_form().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function user_shadow_user_edit_form(Environment $env, array $vars) {
  $curr_user = UserFactory::current($env);
  $user = new User($env, $curr_user->name);
  /** @var Shadow $shadow */
  $shadow = $vars['shadow'];

  $tab = '<h2>' . t('Edit your Account') . '</h2>' . UserFactory::renderUserEditForm($env, $env->getContext());
  $shadow->addTab(t('Edit your Account'), $tab, 1);
  $shadow->addButton('edit-save', t('Update'));
}

/**
 * Implements hook_shadow_user_login_form.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function user_shadow_user_login_form(Environment $env, array $vars) {
  $tab = UserFactory::renderLoginForm($env);
  /** @var Shadow $shadow */
  $shadow = $vars['shadow'];
  $shadow->addTab(t('Your Login Data'), $tab, 1);
  $shadow->addButton('edit-save', 'Login');
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
function user_action_user_edit(Environment $env, array $vars) {
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
function user_action_user_edit_own(Environment $env, array &$vars) {
  user_action_user_edit($env, $vars);
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
function user_user_form_form_submit(Environment $env, array &$vars) {

  // Prepare the response object.
  $response = new \stdClass();

  /** @var FormState $form_state */
  $form_state = $vars['form_state'];

  UserFactory::requestAction($env, $vars['form_state']->getType(), $form_state);

  // Perform the registration of the user.
  $user = UserFactory::load($env, $form_state->getData('username'));
    // Immediate login of the user. TODO: make this optional.
    $user->logIn(NULL, t('Hello %user, thank you for signing up. You are now a registered member.', array('%user' => $form_state->getData('username'))),
      TRUE);
    $vars['user'] = $user;
  // Encode the response JSON code.
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
function user_action_logout(Environment $env, array $vars) {
  $user = UserFactory::current($env, TRUE);
  $logout = $user->logOut();
  exit($logout);
}

/**
 * Implements hook_user_validate().
 *
 * Standard user validation.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function user_user_form_form_validate(Environment $env, &$vars) {

  /** @var FormState $form_state */
  $form_state = $vars['form_state'];

  // Check if using email or username field as an username.
  $username_field = !empty($vars['username_field']) ? $vars['username_field'] : 'username';
  $username = $form_state->getData($username_field);
  $user = UserFactory::load($env, $username);

  $check_password = !($user->exists) || ($form_state->getType() == \Quanta\Common\User::USER_ACTION_REGISTER);

  // Check if some user has the same email as the current one.
  // TODO: find a better way to do the same?
  //exec('grep -r -i -o --include \*.json "\"email\"\:\"' . $user->getEmail() . '\"" ' . $env->dir['users'], $mail_exists);
  $mail_exists = UserFactory::getUserFromField($env, 'email', $form_state->getData('email'));

  // TODO: check also special characters.
  // Minimum user name length.
  if ($username_field == 'username') {

    if (strlen($username) < \Quanta\Common\User::USER_MIN_NAME_LENGTH) {
      $form_state->validationError('username', t('Your username should have at least %length characters', array('%length' => \Quanta\Common\User::USER_MIN_NAME_LENGTH)));
    }
    // Checks that username is alphanumeric.
    if (!\Quanta\Common\Api::is_alphanumeric($user->name) && empty($vars['skip_validate']['username_alphanumeric'])) {
      $form_state->validationError('username', t('Please use only letters and numbers for your username'));
    }
    // For register, check that no user with the same username already exists.
    if ($user->exists && ($form_state->getType() == \Quanta\Common\User::USER_ACTION_REGISTER)) {
      $form_state->validationError('username', t('Username %name already exists!', array('%name' => $user->name)));
    }
  }

  // Checks that email is not used by other users.
  if ($mail_exists && ($form_state->getType() == \Quanta\Common\User::USER_ACTION_REGISTER)) {
    $form_state->validationError('email', t('Email %address already exists!', array('%address' => $user->getEmail())));
  }
  // Checks that email is in a valid format.
  if (!\Quanta\Common\Api::valid_email($form_state->getData('email'))) {
    $form_state->validationError('email', t('Please enter a valid E-mail address!'));
  }
  // Checks that old password matches new.
  if ($check_password && $user->exists && (!empty($user->getData('old_password'))) && ($user->getPassword() != UserFactory::passwordEncrypt($user->getData('old_password')))) {
    $form_state->validationError('old_password', t('The old password you entered is not valid. Please try again.'));
  }

  // Checks password length.
  // TODO: make customizable in hooks.
  if ($check_password && (strlen($form_state->getData('password')) < \Quanta\Common\User::USER_PASSWORD_MIN_LENGTH)) {
    $form_state->validationError('password', t('Password should be at least %length characters long.', array('%length' => \Quanta\Common\User::USER_PASSWORD_MIN_LENGTH)));
  }
  // Checks that the repeated password matches the original.
  if ($check_password && ($form_state->getData('password_rp') != $form_state->getData('password'))) {
    $form_state->validationError('password_rp', 'Password fields are not the same.');
  }

}