<?php

/**
 * Class UserFactory
 * This factory class is used for loading users, etc.
 */
class UserFactory {

  /**
   * Load a node, by checking access and running hooks.
   * @param Environment $env
   * @param $user
   *
   * @return User
   */
  public static function load(Environment $env, $user) {
    $user = new User($env, $user);
    $vars = array ('user' => &$user);
    $env->hook('user_open', $vars);
    return $user;
  }

  /**
   * Create an empty user.
   * @param $env
   * @return User
   */
  public static function buildEmptyUser($env) {
    $user = new User($env, USER_ANONYMOUS);
    return $user;
  }


  /**
   * Create an user with basic values.
   * @param $env
   * @param $name
   * @param array $vars
   * @return User
   * @internal param $node
   */
  public static function buildUser($env, $name, $vars = array ()) {
    $user = new User($env, NODE_NEW);
    $user->setName($name);

    $uservars = array ('first_name', 'last_name');
    foreach ($uservars as $k) {
      if (isset($vars[$k])) {
        $user->setData($k, $vars[$k]);
      }
    }
    if (isset($vars['email'])) {
      $user->setEmail($vars['email']);
    }
    if (isset($vars['title'])) {
      $user->setTitle($vars['title']);
    }

    // TODO: security issue when no pass set!

    $pass = (isset($vars['password'])) ? pwd($vars['password']) : pwd($name . rand(1, 1000000));
    $user->setPassword($pass);
    $user->roles = (isset($vars['roles'])) ? $vars['roles'] : array ();
    $user->realpath = $env->dir['users'] . '/' . $name;

    $user->save();
    return $user;
  }


  /**
   * Request performing an user action: login, logout, register, etc.
   *
   * @param Environment $env
   *   The Environment.
   * @param $action
   *   The Action.
   * @param $form_data
   *
   * @return string
   */
  public static function requestAction($env, $action, $form_data) {
    // TODO: this shit is needed with new approach.
    foreach ($form_data as $k => $v) {
      if (is_array($form_data[$k]) && (count($form_data[$k]) == 1)) {
        $form_data[$k] = array_pop($v);
      }
    }
    // This variable is used to check eventual errors in the user save process.
    $valid = FALSE;
    $curr_user = UserFactory::current($env);
    $response = new StdClass();
    // Check if the current user is allowed to perform the action.
    $can_edit = UserAccess::check($env, $action);
    $user = new User($env, $form_data['name'], '_users', LANGUAGE_NEUTRAL);
    $vars = array('user' => $user, 'form_data' => $form_data);

    // If user does not have the permission, show an error message.
    if (!$can_edit) {
      new Message($env, 'Sorry, you don\'t have the permissions to perform this action: ' . $action, MESSAGE_WARNING, MESSAGE_TYPE_SCREEN);
    }
    // If user has the permission, perform the requested action.
    else {
      switch ($action) {
        case USER_ACTION_REGISTER:
          $env->hook('user_register', $vars);
          $valid = $user->register($form_data);
          break;
        case USER_ACTION_EDIT:
        case USER_ACTION_EDIT_OWN:
        $env->hook('user_update', $vars);
          $valid = $user->update($form_data);
          break;
      }
    }

    // If the newly built user object is valid, rebuild the session to keep it updated.
    if ($valid && isset($user)) {
      $response->redirect = '/' . $env->getRequestedPath();
      $user->rebuildSession();
    }
    // If the newly built user object is not valid, show errors.
    else {
      $response->errors = Message::burnMessages();
    }
    $response_json = json_encode($response);
    return $response_json;
  }

  /**
   * Get the current navigating user.
   * @param $env
   * @param bool $reload
   * @return mixed|User
   */
  static function current($env, $reload = FALSE) {
    static $user;
    // If user has been created already, don't redo the logic.
    if (!empty($user)) {
      return $user;
    }

    // Check if there is a logged in user in session.
    if (!isset($_SESSION['user'])) {
      $user = new User($env, USER_ANONYMOUS);
      $_SESSION['user'] = serialize($user);
    }
    else {
      $user = unserialize($_SESSION['user']);
      // Sometimes there is a request to reload the user object.
      if ($reload) {
        $user = new User($env, $user->name);
      }
      $user->env = $env;
    }
    return $user;
  }
}
