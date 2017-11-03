<?php
define("USER_ANONYMOUS", "guest");
define("ROLE_ANONYMOUS", "anonymous");
define("ROLE_ADMIN", "admin");
define("ROLE_LOGGED", "logged");
define("USER_PASSWORD_MIN_LENGTH", 8);
define("USER_ACTION_EDIT", "user_edit");
define("USER_ACTION_REGISTER", "user_register");
define("USER_VALIDATION_ERROR", "validation_error");
define("USER_MIN_NAME_LENGTH", 4);

/**
 * This class represents an user in the system.
 * Users in Quanta CMS are just extensions of Node objects,
 * AKA folder + json file inside.
 */
class User extends Node {
  public $email;
  // Action being performed on the user
  public $roles = array();
  public $password;

  /**
   * Check if the user entered a correct password.
   * @param $password
   * @return bool
   */
  private function checkPassword($password) {
    if (!isset($this->json->password)) {
      return FALSE;
    }
    return ($this->json->password == pwd($password));
  }

  /**
   * Check if the user is anonymous / guest user.
   * @return bool
   */
  public function isAnonymous() {
    return $this->name == USER_ANONYMOUS;
  }

  /**
   * Load the user object.
   */
  public function load() {
    if ( strlen($this->name) > 0 && $this->exists) {
      $this->loadJSON();
      if (isset($this->json->roles)) {
        $this->roles = (array) $this->json->roles;
      }

      if (isset($this->json->password)) {
        $this->password = $this->json->password;
      }
      if (isset($this->json->email)) {
        $this->email = $this->json->email;
      }
      if (isset($this->json->data)) {
        $this->data = (array) $this->json->data;
      }
    }

    $this->env->hook('user_load', array('user' => $this));
  }

  /**
   * Check if the user has a role.
   * @param $role
   * @return bool
   */
  public function hasRole($role) {
    $has_role = FALSE;
    foreach ($this->roles as $k => $user_role) {
      if (trim($user_role) == trim($role)) {
        $has_role = TRUE;
        break;
      }
    }
    return $has_role;
  }

  /**
   * Returns all the roles of the user.
   * @return array
   */
  public function getRoles() {
    if (empty($this->roles)) {
      return array(USER_ANONYMOUS);
    }
    else {
      return $this->roles;
    }
  }

  /**
   * Log out the user.
   */
  public function logOut() {
    new Message($this->env, 'You logged out', MESSAGE_CONFIRM, MESSAGE_TYPE_SCREEN);
    new Message($this->env, 'User ' . $this->name . ' logged out', MESSAGE_CONFIRM, MESSAGE_TYPE_LOG);
    unset($_SESSION['user']);
    $response = new StdClass();
    $response->redirect = '/' . $this->env->getRequestedPath();
    $response_json = json_encode($response);
    return $response_json;
  }

  /**
   * Try to login.
   * @param $password
   */
  public function logIn($password) {
    // If user dir doesn't exist.
    if (!($this->exists)) {
      new Message($this->env, 'This is not a valid username. Please try to [LOGIN] again', MESSAGE_WARNING, MESSAGE_TYPE_SCREEN);
      new Message($this->env, 'Someone tried to login with wrong username: ' . $this->name, MESSAGE_WARNING, MESSAGE_TYPE_LOG);
    }
    else {
      if ($this->checkPassword($password)) {
        new Message($this->env, 'Welcome ' . $this->name . '! You logged in', MESSAGE_CONFIRM, MESSAGE_TYPE_SCREEN);
        new Message($this->env, 'User ' . $this->name . ' logged in', MESSAGE_CONFIRM, MESSAGE_TYPE_LOG);
        $this->roles += array('logged' => 'logged');
        unset($this->password);
        $_SESSION['user'] = serialize($this);
      }
      else {
        new Message($this->env, 'Wrong username or password. Please <a class="login-link" href="#">try again</a>', MESSAGE_WARNING, MESSAGE_TYPE_SCREEN);
        new Message($this->env, 'User ' . $this->name . ' tried to login with wrong username or password', MESSAGE_WARNING, MESSAGE_TYPE_LOG);
      }
    }
    $response = new StdClass();
    $response->redirect = '/' . $this->env->getRequestedPath();
    $response_json = json_encode($response);
    return $response_json;
  }

  /**
   * Checks if the user is the current user.
   * @return bool
   */
  public function isCurrent() {
    $curr_user = UserFactory::current($this->env);
    return ($curr_user->getName() == $this->getName());
  }

  /**
   * Save the user.
   * @return bool
   */
  public function save() {
    $this->env->hook('user_save', array('user' => $this));
    $this->updateJSON();
    $this->saveJSON();

    if ($this->isCurrent()) {
      $this->rebuildSession();
    }
    return TRUE;
  }

  /**
   * User registration / signup.
   * @param $form_data
   * @return bool|void
   */
  public function register($form_data) {
    $this->password = pwd($form_data['edit-password']);
    $this->email = $form_data['edit-email'];
    $this->setData('first_name', $form_data['edit-first_name']);
    $this->setData('last_name', $form_data['edit-last_name']);
    if ($valid = $this->validate($form_data)) {
      $valid = $this->save();
      //new Message($this->env, 'User ' . $this->name . ' was registered correctly!', MESSAGE_CONFIRM, MESSAGE_TYPE_SCREEN, 'user');
    }
    return $valid;
  }

  // Todo:update() and register() look too much similar.
  public function update($form_data) {
    foreach ($form_data as $k => $value) {
      $field = str_replace('edit-', '', $k);
      switch ($field) {
        case 'name':
        case 'action':
        case 'context':
        case 'password':
        case 'password_rp':
        case 'old_password':
        case 'new_password':
          break;
        case 'email':
          $this->email = $value;
          break;
        default:
          $this->setData($field, $value);
          break;
      }
    }
    if ($valid = $this->validate($form_data)) {
      if (strlen($form_data['edit-password']) > 0) {
        $this->password = pwd($form_data['edit-password']);
      }
      $valid = $this->save();
    }
    if ($valid) {
      new Message($this->env, 'User ' . $this->name . ' was updated correctly!', MESSAGE_CONFIRM, MESSAGE_TYPE_SCREEN, 'user');
    }
    return $valid;
  }

  /**
   * Validate the user as a valid user.
   * @param array $form_data
   * @return bool
   */
  public function validate($form_data = array()) {
    $valid = FALSE;
    if ($this->exists) {
      $existing_user = new User($this->env, $this->name);
    }
    $check_password = !$this->exists || strlen($form_data['edit-password']) > 0;

    exec('grep -r -i -o --include \*.json "\"email\"\:\"' . $form_data['edit-email'] . '\"" ' . $this->env->dir['users'], $mail_exists);

    // TODO: check also special characters.
    if (strlen($this->name) < USER_MIN_NAME_LENGTH) {
      new Message($this->env, 'Your username should have at least ' . USER_MIN_NAME_LENGTH . ' characters', MESSAGE_WARNING);
    }
    elseif (!is_alphanumeric($this->name)) {
      new Message($this->env, 'Please use only letters and numbers for your username', MESSAGE_WARNING);
    }

    elseif ($this->exists && $this->context == USER_ACTION_REGISTER) {
      new Message($this->env, 'Username ' . $this->name . ' already exists!', MESSAGE_WARNING);
    }

    elseif ($mail_exists && (!$this->exists || ($this->email != $existing_user->email))) {
      new Message($this->env, 'Email ' . $this->email . ' already exists!', MESSAGE_WARNING);
    }
    elseif(!valid_email($this->email)) {
      new Message($this->env, 'Please enter a valid E-mail address!', MESSAGE_WARNING);
    }
    elseif ($check_password && $this->exists && ($this->password != pwd($form_data['edit-old_password']))) {
      new Message($this->env, 'The old password you entered is not valid. Please try again.', MESSAGE_WARNING);
    }
    elseif ($check_password && strlen($form_data['edit-password']) < USER_PASSWORD_MIN_LENGTH) {
      new Message($this->env, 'Password should be at least ' . USER_PASSWORD_MIN_LENGTH . ' characters long.', MESSAGE_WARNING);
    }
    elseif ($check_password && pwd($form_data['edit-password_rp']) != pwd($form_data['edit-password'])) {
      new Message($this->env, 'Password fields are not the same.', MESSAGE_WARNING);
    }
    else {
      $this->env->hook('user_validate', array('user' => $this, 'form_data' => $form_data));

      $valid = !($this->env->getData(USER_VALIDATION_ERROR));
    }
    return $valid;

  }

  public function getEmail() {
    return $this->email;
  }

  public function setEmail($email) {
    $this->email = $email;
  }

  public function getPassword() {
    return $this->password;
  }

  public function setPassword($password) {
    $this->password = $password;
  }
  /**
   *
   * Check if user can access a node
   * TODO: should really be in access module...
   * @return User
   */
  public function checkAccess($node = NULL, $action = NULL) {
    // If node doesn't exist, allow no permission to it.
    if (!is_object($node) || !$node->exists) {
      return FALSE;
    }
    $permissions = $node->getPermissions();
    $action = trim(strtolower($action));

    // Conversion to array as of new approach to values.
    if (!is_array($permissions[$action])) {
      $permissions[$action] = array($permissions[$action]);
    }
    $perm_array = array_flip($permissions[$action]);

    if (isset($action) && isset($perm_array[ROLE_ANONYMOUS])) {
      return TRUE;
    } else {
      foreach ($perm_array as $perm_role => $counter) {
        if ($this->hasRole($perm_role)) {
          return TRUE;
        }
      }
    }
    return FALSE;

  }

  // TODO: refactor.
  public function renderEditForm($context) {
    $this->context = $context;
    $user_edit_form = file_get_contents('core/user/tpl/' . $context . '.inc');
    return $user_edit_form;
  }

  // TODO: refactor.
  public function renderLoginForm() {
    $login_form = file_get_contents('core/user/tpl/user_login.inc');
    return $login_form;
  }

  // TODO
  public function renderUploadAvatarForm($context) {

  }


  public function getTime() {
    return 0;
  }

  public function getDate() {
    return date('Y-m-d', $this->getTime());
  }

  public function rebuildSession() {
    $_SESSION['user'] = serialize($this);
  }

  public function updateJSON($ignore = array()) {
    $this->json->email = $this->getEmail();
    $this->json->first_name = $this->getData('first_name');
    $this->json->last_name = $this->getData('last_name');
    $this->json->password = $this->getPassword();
    $this->json->roles = $this->getRoles();
    // Run all Node-related json.
    parent::updateJSON();
  }

  /**
   * @deprecated by UserFactory::current().
   * @param $env
   * @return mixed|User
   */
  public static function current($env) {
    return UserFactory::current($env);
  }

}
