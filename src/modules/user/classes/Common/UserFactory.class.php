<?php

namespace Quanta\Common;
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
   *
   * @param string $language
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
   *
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
  public static function buildUser(Environment $env, $name, array $vars = array()) {
    $user = new User($env, \Quanta\Common\Node::NODE_NEW);
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
    $pass = (isset($vars['password'])) ? UserFactory::passwordEncrypt($vars['password']) : UserFactory::passwordEncrypt($name . rand(1, 1000000));
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
  public static function getUserFromField(Environment $env, $field, $value) {
    // This is the best we found so far from retrieving one user from one field...
    $command = 'grep -r -i -o --include \*.json "\"' . $field . '\"\:\"' . $value . '\"" ' . $env->dir['users'];
    exec($command, $results);
    if (!empty($results)) {
    $explode = explode('/', array_pop($results));
    if (count($explode) > 2) {
      return $explode[count($explode) - 2];
    }
    else {
      return NULL;
    }
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
   * @param FormState $form_state
   *  The Form State.
   *
   * @return string
   *  A Json representation of the user.
   */
  public static function requestAction(Environment $env, $action, FormState $form_state) {

    $response = new \stdClass();

    $user = new User($env, $form_state->getData('username'), '_users');

    $vars = array('user' => $user);
  
    $form_items = $form_state->data;

    // Check if the current user is allowed to perform the requested action.
    $access_check = UserAccess::check($env, $action, $vars);
    if ($access_check) {
      switch ($action) {
        case \Quanta\Common\User::USER_ACTION_REGISTER:
        case \Quanta\Common\User::USER_ACTION_EDIT:
        case \Quanta\Common\User::USER_ACTION_EDIT_OWN:

          // Create a default title for the user node, if it's not set.
          $user->setTitle($user->getFirstName() . ' ' . $user->getLastName());

          foreach ($form_items as $key => $value) {
            switch ($key) {
              case 'first_name':
                if (!empty($value)) {
                  $user->setFirstName($value);
                }
                break;
              case 'last_name':
                if (!empty($value)) {
                  $user->setLastName($value);
                }
              break;

              case 'email':
                if (!empty($value)) {
                  $user->setEmail($value);
                }
              break;

              case 'edit_title':
                if (!empty($value)) {
                  $user->setTitle($value);
                }
              break;
              
              default:
                if(!in_array($key,\Quanta\Common\User::USER_IGNORE_FIELDS)){
                  $user->setAttributeJSON($key, $value);
                }
                break;
            }
          } 

          // Hook user presave.
          $env->hook('user_presave', $vars);

          // Set user's password.
          if (!empty($form_state->getData('password'))) {
            $user->setPassword(UserFactory::passwordEncrypt($form_state->getData('password')));
          }
          // If the newly built user object is valid, rebuild the session to keep it updated.
          if ($user->save()) {
            $user->rebuildSession();
          }
          else {
            die("ERROR");
          }
      }
    }
    else {
      // Access denied.
      $response->redirect = '/403';
    }

    // Encode the response JSON code.
    $response_json = json_encode($response);
    return $response_json;
  }

  /**
   * Get the current navigating user.
   * @param $env
   * @param bool $reload
   * @return mixed|User
   */
  static function current(Environment $env, $reload = FALSE) {
    static $user;
    // If user has been created already, don't redo the logic.
    if (!empty($user) && !$reload) {
      return $user;
    }

    // Check if there is a logged in user in session.
    if (!isset($_SESSION['user'])) {
      $token = self::getBearerToken();
      if ($token) {
          $decodedData = self::verifyToken($env, $token);
          if ($decodedData && isset($decodedData['user_name'])) {
              print_r('welcome: ' . $decodedData['user_name']);
              die();
              $user = new User($env, $decodedData['user_name']);
          } else {
              $user = new User($env, \Quanta\Common\User::USER_ANONYMOUS); // Invalid token or expired
          }
      } else {
          $user = new User($env, \Quanta\Common\User::USER_ANONYMOUS); // No token provided
      }
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
   *
   * @deprecated from next Quanta version.
   *
   * @param Environment $env
   *   The Environment.
   * @param sring $context
   *   The current context.
   *
   * @return bool|string
   */
  public static function renderUserEditForm(Environment $env, $context) {
    $user_edit_form = file_get_contents($env->getModulePath('user') . '/tpl/user_edit.inc');
    return $user_edit_form;
  }


  /**
   * Renders a Login form.
   * @deprecated from next Quanta version.
   * TODO: refactor and move elsewhere.
   *
   * @return string
   */
  public static function renderLoginForm(Environment $env) {
    $login_form = file_get_contents($env->getModulePath('user') . '/tpl/user_login.inc');
    return $login_form;
  }

   /**
   * Renders a Login form.
   * @deprecated from next Quanta version.
   * TODO: refactor and move elsewhere.
   *
   * @return string
   */
  public static function renderResetPasswordForm(Environment $env) {
    $reset_form = file_get_contents($env->getModulePath('user') . '/tpl/user_reset_password.inc');
    return $reset_form;
  }

  /**
   * Encrypts a text into a password.
   * TODO: create a hook to change the default algohoritm.
   *
   * @param string $pass
   *   The non-encrypted password.
   *
   * @return string
   *   The encrypted password.
   */
  public static function passwordEncrypt($pass) {
    return substr((md5(substr($pass, 0, 5) . 'ABC' . substr($pass, 5, 2) . 'nginE')) . md5($pass), 0, 50);
  }

    /**
   * Check if the user entered a correct password.
   *
   * @param Node $user
   *   The User Node.
   * @param string $password
   *   The entered password.
   *
   * @return bool
   *   Return true if the user/pass combination matches.
   */
  public static function checkPassword($user,$password) {
    if (!isset($user->json->password)) {
      return FALSE;
    }
    // We compare with the encrypted password.
    return ($user->json->password == UserFactory::passwordEncrypt($password));
  }

  public static function generateToken(Environment $env, $user_name){
    $issuedAt = time();
    $expiration = $issuedAt + (60 * 60 * 24 * 90); // Token valid for approximately 3 months
    $secret_key = $env->getData('JWT_SECRET_KEY');
    $payload = [
        'user_name' => $user_name,
        'iat' => $issuedAt,
        'exp' => $expiration
    ];

    return \Firebase\JWT\JWT::encode($payload, $secret_key, 'HS256');
  }

  public static function verifyToken($env, $token){
    $secret_key = $env->getData('JWT_SECRET_KEY');
    try {
        $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secret_key, 'HS256'));
        return (array) $decoded; // Returns payload as an array
    } catch (Exception $e) {
        return null; // Invalid token
    }
  }

  public static function getBearerToken() {
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $matches = [];
        if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
            return $matches[1];
        }
    }
    return null;
}
}
