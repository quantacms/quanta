<?php

/**
 * Class UserFactory
 * This factory class is used for loading users, etc.
 */
class UserFactory {

  /**
   * Load an user, by checking access and running hooks.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @param string $username
   *   The name of the user.
   * @param $language
   *   In which language to load the user.
   *
   * @return User
   *   The User.
   */
  public static function load(Environment $env, $username, $language = NULL) {
    if (empty($language)) {
      $language = Localization::getLanguage($env);
    }
    $user = new User($env, $username, NULL, $language);
    $vars = array('user' => &$user);
    $env->hook('user_open', $vars);
    return $user;
  }

  /**
   * Create an user with basic values.
   * @param Environment $env
   *   The Environment.
   * @param string $name
   *   The user name.
   * @param array $vars
   *   An array of variables.
   *
   * @return User
   *   The constructed user object.
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
    $user->path = $env->dir['users'] . '/' . $name;
    $env->hook('user_presave', $vars);
    $user->save();
    return $user;
  }

  /**
   * Retrieves an User from a parameter.
   *
   * @param Environment $env
   *   The Environment.
   * @param string $field
   *   The name of the field.
   * @param string $value
   *   An value of the field.
   *
   * @return User
   *   The retrieved user object.
   */
  public static function getUserFromField($env, $field, $value) {
    $command = 'grep -r -i -o --include \*.json "\"' . $field . '\"\:\"' . $value . '\"" ' . $env->dir['users'];
    exec($command, $results);
    $explode = explode('/', array_pop($results));
    if (count($explode) > 2) {
      return $explode[count($explode) - 2];
    }
    else {
      return NULL;
    }
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
    $user = new User($env, array_pop($form_data['name']), '_users');
    $env->setContext($action);
    // TODO: this shit is needed with new approach.
    foreach ($form_data as $k => $v) {

      if (is_array($form_data[$k]) && (count($form_data[$k]) == 1)) {
        $v = array_pop($v);
      }
      $user->setData($k, $v);
    }

    // Check if the current user is allowed to perform the action.
    $can_edit = UserAccess::check($env, $action);

    // If user does not have the permission, show an error message.
    if (!$can_edit) {
      new Message($env, t('Sorry, you don\'t have the permissions to perform this action: ' . $action), MESSAGE_WARNING, MESSAGE_TYPE_SCREEN);
    }
    // If user has the permission, perform the requested action.
    else {
      switch ($action) {
        case USER_ACTION_REGISTER:
          if ($user->validate()) {
            $user->update();
          }
          break;
        case USER_ACTION_EDIT:
        case USER_ACTION_EDIT_OWN:

        // If the newly built user object is valid, rebuild the session to keep it updated.
        if ($user->update()) {
          $user->rebuildSession();
        }
        break;

        default:
          break;
      }
    }
    return $user;
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
    if (!empty($user) && !$reload) {
      return $user;
    }

    // Check if there is a logged in user in session.
    if (!isset($_SESSION['user'])) {
      $user = new User($env, USER_ANONYMOUS);
    }
    else {
      $user = unserialize($_SESSION['user']);
      // Sometimes there is a request to reload the user object.
      if ($reload) {
        $user = new User($env, $user->name);
      }
    }
    return $user;
  }


  /**
   * Renders an user edit form
   * @param $context
   * @return bool|string
   */
  public static function renderUserEditForm($env, $context) {
    $user_edit_form = file_get_contents($env->getModulePath('user') . '/tpl/user_edit.inc');
    return $user_edit_form;
  }


  /**
   * Renders a Login form.
   * TODO: refactor and move elsewhere.
   *
   * @return string
   */
  public static function renderLoginForm($env) {
    $login_form = file_get_contents($env->getModulePath('user') . '/tpl/user_login.inc');
    return $login_form;
  }
}
