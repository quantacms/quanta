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
  // TODO: fix cookies.
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
  $module_path = $env->getModulePath('user');
  $env->addInclude($module_path . '/js/user.js');
  $env->addInclude($module_path . '/css/user.css');
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
function user_doctor_setup($env, $vars) {
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
      'language' => LANGUAGE_NEUTRAL,
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
  $username = array_pop($vars['data']['username']);
  // We allow also using email for logging in.
  if (valid_email($username)) {
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
    default:
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
function user_action_user_register($env, $vars) {
  // Prepare the response object.
  $response = new stdClass();
  $user = UserFactory::requestAction($env, $vars['data']['action'], $vars['data']);
  // Check if there are validation errors.
  if (!empty($user->getData('validation_errors'))) {
    foreach ($user->getData('validation_errors') as $error) {
      new Message($env, $error, MESSAGE_WARNING);
    }
    $response->errors = Message::burnMessages();
  }
  else {
    $username = array_pop($vars['data']['name']);
    $user = UserFactory::load($env, $username);
    $user->logIn(NULL, t('Hello %user, thank you for signing up. You are now a registered member.', array('%user' => $username)), TRUE);
    $response->redirect = NodeFactory::current($env)->getName();
  }

  // Encode the response JSON code.
  $response_json = json_encode($response);

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
  $user = UserFactory::current($env, TRUE);
  $logout = $user->logOut();
  exit($logout);
}
