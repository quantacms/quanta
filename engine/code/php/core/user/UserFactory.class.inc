<?php

/**
 * Class UserFactory
 * This factory class is used for loading users, etc.
 */
class UserFactory {

  /**
   * Load a node, by checking access and running hooks.
   * @param $env
   * @param $node
   */
  public static function load(Environment $env, $user, $tpl = NULL) {
    $user = new User($env, $user);
    $env->hook('user_open', array('user' => &$user), NULL, $tpl);
    return $user;
  }

  /**
   * Create an empty user.
   * @param $env
   */
  public static function buildEmptyUser($env) {
    $user = new User($env, USER_ANONYMOUS);
    return $user;
  }


  /**
   * Create an user with basic values.
   * @param $env
   * @param $node
   */
  public static function buildUser($env, $name, $vars = array()) {
    $user = new User($env, NODE_NEW);
    $user->setName($name);

    $uservars = array('first_name', 'last_name');
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
    $user->roles = (isset($vars['roles'])) ? $vars['roles'] : array();
    $user->realpath = $env->dir['users'] . '/' . $name;

    $user->save();
    return $user;
  }


  /**
   * @param Environment $env
   * @param $action
   * @param $form_data
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
    $edit_same_user = $curr_user->name == $form_data['edit-name'];
    $response = new StdClass();
    $can_edit =
      ($action == USER_ACTION_REGISTER && $curr_user->checkAccess(NULL, USER_ACTION_REGISTER)) ||
      ($action == USER_ACTION_EDIT && $curr_user->checkAccess(NULL, USER_ACTION_EDIT)) ||
      ($action == USER_ACTION_EDIT && $edit_same_user && $curr_user->checkAccess(NULL, USER_ACTION_EDIT_OWN));
    if (!$can_edit) {
      new Message($env, 'Sorry, you don\'t have the permissions to perform this action.', MESSAGE_WARNING, MESSAGE_TYPE_SCREEN);
    } else {
      $user = new User($env, $form_data['edit-name'], array(ROLE_LOGGED => ROLE_LOGGED));
      $user->context = $action;
      if ($action == USER_ACTION_REGISTER) {
        $valid = $user->register($form_data);
      } elseif ($action == USER_ACTION_EDIT) {
        $valid = $user->update($form_data);
      }
    }

    if ($valid) {
      $response->redirect = '/' . $env->getRequestedPath();
      $user->rebuildSession();
    } else {
      $response->errors = Message::burnMessages();
    }
    $response_json = json_encode($response);
    return $response_json;
  }

  /**
   * Get the current navigating user.
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
