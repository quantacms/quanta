<?php
/**
 *
 */
define("USER_ANONYMOUS", "guest");
define("ROLE_ANONYMOUS", "anonymous");
define("ROLE_ADMIN", "admin");
define("ROLE_LOGGED", "logged");
define("USER_PASSWORD_MIN_LENGTH", 8);
define("USER_ACTION_EDIT", "user_edit");
define("USER_ACTION_EDIT_OWN", "user_edit_own");
define("USER_ACTION_REGISTER", "user_register");
define("USER_VALIDATION_ERROR", "validation_error");
define("USER_MIN_NAME_LENGTH", 4);

/**
 * This class represents an user in the system.
 * Users in Quanta CMS are just extensions of Node objects,
 * AKA a folder + json file inside.
 */
class User extends Node {
  /** @var string $email */
  public $email;
  /** @var array $roles */
  public $roles = array();
  /** @var string $password */
  public $password;

  /**
   * Check if the user entered a correct password.
   *
   * @param string $password
   *   The entered password.
   *
   * @return bool
   *   Return true if the user/pass combination matches.
   */
  private function checkPassword($password) {
    if (!isset($this->json->password)) {
      return FALSE;
    }
    // We compare with the encrypted password.
    return ($this->json->password == pwd($password));
  }

  /**
   * Check if the user is anonymous / guest user.
   *
   * @return bool
   *   Returns true if the current user is Anonymous.
   */
  public function isAnonymous() {
    return $this->name == USER_ANONYMOUS;
  }

  /**
   * Load the user object.
   */
  public function load() {
    if (strlen($this->name) > 0 && $this->exists) {
      $this->loadJSON();
      if (isset($this->json->roles)) {
        $this->roles = (array)$this->json->roles;
      }

      if (isset($this->json->password)) {
        $this->password = $this->json->password;
      }
      if (isset($this->json->email)) {
        $this->email = $this->json->email;
      }
      if (isset($this->json->data)) {
        $this->data = (array)$this->json->data;
      }
    }

    $this->env->hook('user_load', array('user' => $this));
  }

  /**
   * Check if the user has a role.
   *
   * @param $role
   *   The role to check.
   *
   * @return bool
   *   Returns true, if the user has a certain role.
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
   *
   * @return array
   *   All the User's roles.
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
   *
   * @return mixed $response_json
   *   The JSON-encoded response to the logout action.
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
   * Perform a login action on an user object.
   *
   * @param $password
   *   The password inserted by the user.
   *
   * @param $success_message
   *   A custom success message to show.
   *
   * @return string
   *   A JSON encrypted response to the login action.
   */
  public function logIn($password, $success_message = NULL) {
    // Create a default success message.
    if (!isset($success_message)) {
      $success_message = 'Welcome ' . $this->getName() . '! You logged in';
    }
    // If user dir doesn't exist.
    if (!($this->exists)) {
      new Message($this->env, $this->getName() . ' is not a valid username. Please try to [LOGIN] again', MESSAGE_WARNING, MESSAGE_TYPE_SCREEN);
      new Message($this->env, 'Someone tried to login with wrong username: ' . $this->name, MESSAGE_WARNING, MESSAGE_TYPE_LOG);
    }
    else {
      if ($this->checkPassword($password)) {
        new Message($this->env, $success_message, MESSAGE_CONFIRM, MESSAGE_TYPE_SCREEN);
        new Message($this->env, 'User ' . $this->getName() . ' logged in', MESSAGE_CONFIRM, MESSAGE_TYPE_LOG);
        $this->roles += array('logged' => 'logged');
        unset($this->password);
        $_SESSION['user'] = $this->serializeForSession();
      }
      else {
        // Show an error message for wrong password.
        new Message($this->env, 'Wrong username or password. Please <a class="login-link" href="#">try again</a>', MESSAGE_WARNING, MESSAGE_TYPE_SCREEN);

        // Create a log entry.
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
   *
   * @return bool
   *   Returns true if the user object is the same as the current user.
   */
  public function isCurrent() {
    $curr_user = UserFactory::current($this->env);
    return ($curr_user->getName() == $this->getName());
  }

  /**
   * Save the user object.
   *
   * @return bool
   *   Returns true if the save action was completed without errors.
   */
  public function save() {
    if (empty($this->realpath)) {
      $this->realpath = $this->env->dir['users'] . '/' . $this->getName();
    }

    $this->env->hook('user_save', array('user' => $this));
    $this->updateJSON();
    $this->saveJSON();

    if ($this->isCurrent()) {
      $this->rebuildSession();
    }
    // TODO: probably always true at this point, but are we sure?
    return TRUE;
  }

  /**
   * Perform an user registration / signup action.
   *
   * @param $form_data
   *   The data to use for building the user.
   *
   * @return bool
   *   Returns true if the user was registered correctly
   */
  public function register($form_data) {
    $input_password = $form_data['edit-password'];
    $this->password = pwd($input_password);
    $this->email = $form_data['edit-email'];
    // TODO: what to do when registering the user with predefined roles?
    $this->roles = array();
    $this->setData('first_name', $form_data['edit-first_name']);
    $this->setData('last_name', $form_data['edit-last_name']);
    // Validate and save the user.
    if ($valid = $this->validate($form_data)) {
      $valid = $this->save();
    }

    if ($valid) {
      $message = 'Welcome ' . $this->getData('first_name') . '! You have successfully registered to the site.';
      $this->exists = TRUE;
      $this->logIn($input_password, $message);
    }
    return $valid;
  }

  // Todo:update() and register() look too much similar.
  // TODO: maybe just rely on node for saving, no need for all this customization.
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
   *
   * @param array $form_data
   *   The data to use to build the user object.
   *
   * @return bool
   *   Returns true if the constructed user is valid.
   */
  public function validate($form_data = array()) {
    $valid = FALSE;
    $existing_user = UserFactory::load($this->env, $this->name);

    $check_password = !$this->exists || strlen($form_data['edit-password']) > 0;

    exec('grep -r -i -o --include \*.json "\"email\"\:\"' . $form_data['edit-email'] . '\"" ' . $this->env->dir['users'], $mail_exists);

    // TODO: check also special characters.
    if (strlen($this->name) < USER_MIN_NAME_LENGTH) {
      new Message($this->env, 'Your username should have at least ' . USER_MIN_NAME_LENGTH . ' characters', MESSAGE_WARNING);
    }
    elseif (!is_alphanumeric($this->name)) {
      new Message($this->env, 'Please use only letters and numbers for your username', MESSAGE_WARNING);
    }
    // For register, check that no user with the same username already exists.
    elseif ($this->exists && $form_data['action'] == USER_ACTION_REGISTER) {
      new Message($this->env, 'Username ' . $this->name . ' already exists!', MESSAGE_WARNING);
    }

    elseif ($mail_exists && (!$this->exists || ($this->email != $existing_user->email))) {
      new Message($this->env, 'Email ' . $this->email . ' already exists!', MESSAGE_WARNING);
    }
    elseif (!valid_email($this->email)) {
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

  /**
   * Gets the email for this user
   *
   * @return string
   *   The email of this user.
   */
  public function getEmail() {
    return $this->email;
  }

  /**
   * Sets the email for this user.
   *
   * @param $email
   *   The email to be set.
   */
  public function setEmail($email) {
    $this->email = $email;
  }

  /**
   * Gets the password for this user
   *
   * @return string
   *   The encrypted password of this user.
   */
  public function getPassword() {
    return $this->password;
  }

  /**
   * Sets the password for this user.
   *
   * @param $password
   *   The encrypted password to be set.
   */
  public function setPassword($password) {
    $this->password = $password;
  }

  // TODO: refactor.
  public function renderEditForm($context) {
    $user_edit_form = file_get_contents('core/user/tpl/' . $context . '.inc');
    return $user_edit_form;
  }


  /**
   * Renders a Login form.
   * TODO: refactor and move elsewhere.
   *
   * @return string
   */
  public function renderLoginForm() {
    $login_form = file_get_contents('core/user/tpl/user_login.inc');
    return $login_form;
  }

  /**
   * Rebuild the User object in session, based on current user.
   */
  public function rebuildSession() {
    $_SESSION['user'] = $this->serializeForSession();
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

  public function serializeForSession() {
    $serialized = clone($this);
    unset($serialized->father);
    unset($serialized->env);
    return serialize($serialized);

  }

}
