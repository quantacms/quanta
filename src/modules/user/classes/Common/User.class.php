<?php
namespace Quanta\Common;
/**
 * This class represents an user in the system.
 *
 * Users in Quanta CMS are just extensions of Node objects.
 */
class User extends Node {
  const USER_ANONYMOUS = "anonymous";
  const ROLE_ANONYMOUS = "anonymous";
  const ROLE_ADMIN = "admin";
  const ROLE_LOGGED = "logged";

  // TODO: seems arbitrary. Use a hook instead...
  const USER_PASSWORD_MIN_LENGTH = 8;
  const USER_MIN_NAME_LENGTH = 4;
  const USER_ACTION_LOGIN = "user_login";
  const USER_ACTION_EDIT = "user_edit";
  const USER_ACTION_EDIT_OWN = "user_edit_own";
  const USER_ACTION_REGISTER = "user_register";
  const USER_VALIDATE = "user_validate";
  const USER_VALIDATION_ERROR = "validation_error";

  /** @var array $roles */
  public $roles = array();

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
        $this->setData('password', $this->json->password);
      }
      if (isset($this->json->email)) {
        $this->setEmail($this->json->email);
      }
      if (isset($this->json->first_name)) {
        $this->setFirstName($this->json->first_name);
      }
      if (isset($this->json->last_name)) {
        $this->setLastName($this->json->last_name);
      }
      if (isset($this->json->data)) {
        $this->data = (array)$this->json->data;
      }
    }
    $vars = array('user' => &$this);
    $this->env->hook('user_load', $vars);
  }

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
    return ($this->json->password == UserFactory::passwordEncrypt($password));
  }

  /**
   * Check if the user is anonymous / guest user.
   *
   * @return bool
   *   Returns true if the current user is Anonymous.
   */
  public function isAnonymous() {
    return $this->name == self::USER_ANONYMOUS;
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
      return array(self::USER_ANONYMOUS);
    }
    else {
      return $this->roles;
    }
  }

  /**
   * Sets all the roles of the user.
   *
   * @param array
   *   All the User's roles.
   */
  public function setRoles($roles) {
    $this->roles = $roles;
  }

  /**
   * Log out the user.
   *
   * @return mixed $response_json
   *   The JSON-encoded response to the logout action.
   */
  public function logOut() {
    new Message($this->env,
      t('You logged out'),
      \Quanta\Common\Message::MESSAGE_CONFIRM,
      \Quanta\Common\Message::MESSAGE_TYPE_SCREEN
    );
    new Message($this->env,
      t('User !user logged out', array('!user' => $this->name)),
      \Quanta\Common\Message::MESSAGE_CONFIRM,
      \Quanta\Common\Message::MESSAGE_TYPE_LOG
    );
    unset($_SESSION['user']);

    // TODO: adapt cookies.
    $response = new \stdClass();
    $response->redirect = '/' . $this->env->getRequestedPath();
    $response_json = json_encode($response);
    return $response_json;
  }

  /**
   * Perform a login action on an user object.
   *
   * @param string $password
   *   The password inserted by the user.
   *
   * @param string $success_message
   *   A custom success message to show.
   *
   * @param bool $force_login
   *   If true, bypass login/password check.
   *
   * @return string
   *   A JSON encrypted response to the login action.
   */
  public function logIn($password, $success_message = NULL, $force_login = FALSE) {
		// Create a default success message.
    if (!isset($success_message)) {
      $success_message = 'Welcome ' . $this->getTitle() . '! You logged in';
    }
    // If user dir doesn't exist.
    if (!($this->exists)) {
      new Message($this->env, $this->getName() . ' is not a valid username. Please try to [LOGIN] again', \Quanta\Common\Message::MESSAGE_WARNING, \Quanta\Common\Message::MESSAGE_TYPE_SCREEN);
      new Message($this->env, 'Someone tried to login with wrong username: ' . $this->name, \Quanta\Common\Message::MESSAGE_WARNING, \Quanta\Common\Message::MESSAGE_TYPE_LOG);
    }
    else {
      if ($this->checkPassword($password) || $force_login) {
				new Message($this->env,
          $success_message,
          \Quanta\Common\Message::MESSAGE_CONFIRM,
          \Quanta\Common\Message::MESSAGE_TYPE_SCREEN
        );
        new Message($this->env,
          t('User !user logged in', array('!user' => $this->getName())),
          \Quanta\Common\Message::MESSAGE_CONFIRM,
          \Quanta\Common\Message::MESSAGE_TYPE_LOG
        );
        $this->roles += array(self::ROLE_LOGGED => self::ROLE_LOGGED);
        $_SESSION['user'] = $this->serializeForSession();
      }
      else {
        // Show an error message for wrong password.
        new Message($this->env,
          t('Wrong username or password. Please try again'),
          \Quanta\Common\Message::MESSAGE_WARNING,
          \Quanta\Common\Message::MESSAGE_TYPE_SCREEN
        );

        // Create a log entry.
        new Message($this->env,
          t('User !name tried to login with wrong username or password', array('!name' => $this->name)),
            \Quanta\Common\Message::MESSAGE_WARNING,
            \Quanta\Common\Message::MESSAGE_TYPE_LOG
          );
      }
    }
    // TODO: use a response object.
    $response = new \stdClass();
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
    if (empty($this->path)) {
      $this->path = $this->env->dir['users'] . '/' . $this->getName();
    }
    $vars = array('user' => $this);
    $this->env->hook('user_save', $vars);
    $this->env->hook($this->env->getContext(), $vars);

    // Reload the node JSON.
    $this->updateJSON();
    // Save the node json (excluding some fields such as path.)
    $this->saveJSON();
    $this->env->hook('user_after_save', $vars);

    // If the currently logged in user was modified, reload it in the session.
    if ($this->isCurrent()) {
      $this->rebuildSession();
    }
    return TRUE;
  }

  /**
   * Action to register or update an existing user.
   *
   * @return bool
   *   TRUE if the user is valid and the process went smooth.
   */
  public function update() {
    // Create a default title for the user node, if it's not set.
    $this->setTitle($this->getData('first_name') . ' ' . $this->getData('last_name'));
    $this->setFirstName($this->getData('first_name'));
    $this->setLastName($this->getData('last_name'));
    $this->setEmail($this->getData('email'));
    $this->setPassword(UserFactory::passwordEncrypt($this->getPassword()));
    $valid = $this->save();
    return $valid;
  }

  /**
   * Validate the user as a valid user.
   *
   * TODO: start moving validations in hooks only. Take out of class itself.
   *
   * @return bool
   *   Returns true if the constructed user is valid.
   */
  public function validate() {
    // Allow skipping standard user validation using a hook.
    $vars = array('user' => $this, 'skip_validate' => array());
    // Pre validate hook (to interact with validation criterias.
    $this->env->hook('user_pre_validate', $vars);
    // Validate hook.
    $this->env->hook('user_validate', $vars);

    return empty($this->getData('validation_errors'));
  }

  /**
   * Gets the email for this user
   *
   * @return string
   *   The email of this user.
   */
  public function getEmail() {
    return $this->getData('email');
  }

  /**
   * Sets the email for this user.
   *
   * @param $email
   *   The email to be set.
   */
  public function setEmail($email) {
    $this->setData('email', $email);
  }

  /**
   * Gets the last name for this user
   *
   * @return string
   *   The last name of this user.
   */
  public function getLastName() {
    return $this->getData('last_name');
  }

  /**
   * Sets the last name for this user.
   *
   * @param $last_name
   *   The last name to be set.
   */
  public function setLastName($last_name) {
    $this->setData('last_name', $last_name);
  }


  /**
   * Gets the first name for this user
   *
   * @return string
   *   The first name of this user.
   */
  public function getFirstName() {
    return $this->getData('first_name');
  }

  /**
   * Sets the first name for this user.
   *
   * @param $first_name
   *   The first name to be set.
   */
  public function setFirstName($first_name) {
    $this->setData('first_name', $first_name);
  }

  /**
   * Gets the password for this user
   *
   * @return string
   *   The encrypted password of this user.
   */
  public function getPassword() {
    return $this->getData('password');
  }

  /**
   * Sets the password for this user.
   *
   * @param $password
   *   The encrypted password to be set.
   */
  public function setPassword($password) {
    $this->setData('password', $password);
  }

  /**
   * Rebuild the User object in session, based on current user.
   */
  public function rebuildSession() {
    // TODO: always appropriate to set logged when rebuilding a session?
    $this->roles += array('logged' => 'logged');
    $_SESSION['user'] = $this->serializeForSession();
  }

  /**
   * Update the user's json attributes.
   *
   * @param array $ignore
   *   Fields to be ignored
   */
  public function updateJSON(array $ignore = array()) {
    $this->json->email = $this->getEmail();
    $this->json->first_name = $this->getData('first_name');
    $this->json->last_name = $this->getData('last_name');
    $this->json->password = $this->getPassword();
    $this->json->roles = $this->getRoles();

    // Run all Node-related json.
    parent::updateJSON($ignore);
  }

  /**
   * Serialize the user data to be saved in the Session.
   *
   * @return string
   */
  public function serializeForSession() {
    $serialized = clone($this);
    unset($serialized->father);
    unset($serialized->env);
    return serialize($serialized);
  }
}
