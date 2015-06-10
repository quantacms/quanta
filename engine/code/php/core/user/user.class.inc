<?php
define("USER_ANONYMOUS", "anonymous");
define("ROLE_ANONYMOUS", "anonymous");
define("ROLE_LOGGED", "logged");
define("USER_PASSWORD_MIN_LENGTH", 8);
define("USER_ACTION_EDIT", "user_edit");
define("USER_ACTION_REGISTER", "user_register");


/**
 * This class represents an user in the system.
 */
class User {
  public $name;
  public $email;
  // Action being performed on the user
  public $context;
  public $roles = array();
  public $exists;
  private $json;
  private $env;
  private $dir;
  public $password;
  public $data = array();

  // Construct the Node.
  public function __construct(&$env, $name = USER_ANONYMOUS, $roles = array(ROLE_ANONYMOUS => ROLE_ANONYMOUS)) {
    $this->env = $env;
    $this->name = $name;
    $this->roles = $roles;
    $this->dir = $this->env->dir['users'] . '/' . strtolower($this->name);
    $this->exists = is_dir($this->dir);
    if ($name != USER_ANONYMOUS && strlen($name) > 0 && $this->exists) {
      $this->json = json_decode(file_get_contents($this->dir . '/userdata.json'));
      $this->roles = (array) $this->json->roles;
      if (isset($this->json->password)) {
        $this->password = $this->json->password;
      }
      if (isset($this->json->email)) {
        $this->email = $this->json->email;
      }
      if (isset($this->json->data)) {
        $this->data = (array) $this->json->data;
      }
      $this->load();
    }
  }

  public function setData($key, $value) {
    $this->data[$key] = $value;
  }

  public function getData($key) {
    return (isset($this->data[$key]) ? $this->data[$key] : FALSE);
  }

  /**
   * Get the current navigating user.
   */
  static function current($env) {
    if (!isset($_SESSION['user'])) {
      $_SESSION['user'] = serialize(new User($env));
    }

    $user = unserialize($_SESSION['user']);
    $user->env = $env;
    return $user;
  }

  private function checkPassword($password) {
    return ($this->password == pwd($password));
  }

  public function load() {
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
      new Message($this->env, 'This is not a valid username. Please <a class="login-link" href="#">try again</a>', MESSAGE_WARNING, MESSAGE_TYPE_SCREEN);
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

  public function save() {
    $this->env->hook('user_save', array('user' => $this));
    $this->saveJSON();
    return TRUE;
  }

  /**
   * Save the JSON dump of the node
   */
  public function saveJSON() {
    if (!is_dir($this->dir)) {
      mkdir($this->dir) or die('cannot create dir: ' . $this->dir);
    }
    $user_json = clone $this;
    $jsonpath = $user_json->dir . '/userdata.json';
    unset($user_json->dir);
    unset($user_json->context);
    unset($user_json->env);
    unset($user_json->json);
    unset($user_json->exists);

    $fh = fopen($jsonpath, 'w+');
    fwrite($fh, json_encode($user_json));
    fclose($fh);

    unset($user_json);
  }

  /**
   * User registration / signup.
   * @param $userdata
   * @return bool|void
   */
  private function register($userdata) {
    $this->password = pwd($userdata['edit-password']);
    $this->email = $userdata['edit-email'];
    $this->setData('first_name', $userdata['edit-first_name']);
    $this->setData('last_name', $userdata['edit-last_name']);
    if ($valid = $this->validate($userdata)) {
      $valid = $this->save();
      new Message($this->env, 'User ' . $this->name . ' was registered correctly!', MESSAGE_CONFIRM, MESSAGE_TYPE_SCREEN, 'user');
    }
    return $valid;
  }

  // Todo:update() and register() look too much similar.

  private function update($userdata) {
    foreach ($userdata as $k => $value) {
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
    if ($valid = $this->validate($userdata)) {
      if (strlen($userdata['edit-password']) > 0) {
        $this->password = pwd($userdata['edit-password']);
      }
      $valid = $this->save();
    }
    if ($valid) {
      new Message($this->env, 'User ' . $this->name . ' was updated correctly!', MESSAGE_CONFIRM, MESSAGE_TYPE_SCREEN, 'user');
    }
    return $valid;
  }

  /**
   * @param Environment $env
   * @param $action
   * @param $userdata
   */
  public static function requestAction($env, $action, $userdata) {
    // This variable is used to check eventual errors in the user save process.
    $valid = FALSE;
    $curr_user = User::current($env);
    $edit_same_user = $curr_user->name == $userdata['edit-name'];
    $response = new StdClass();
    $can_edit =
      ($action == USER_ACTION_REGISTER && $curr_user->checkAccess(NULL, USER_ACTION_REGISTER)) ||
      ($action == USER_ACTION_EDIT && $curr_user->checkAccess(NULL, USER_ACTION_EDIT)) ||
      ($action == USER_ACTION_EDIT && $edit_same_user && $curr_user->checkAccess(NULL, USER_ACTION_EDIT_OWN));
    if (!$can_edit) {
      new Message($env, 'Sorry, you don\'t have the permissions to perform this action.', MESSAGE_WARNING, MESSAGE_TYPE_SCREEN);
    }
    else {
      $user = new User($env, $userdata['edit-name'], array(ROLE_LOGGED => ROLE_LOGGED));

      if ($action == USER_ACTION_REGISTER) {
        $valid = $user->register($userdata);
      }
      elseif ($action == USER_ACTION_EDIT) {
        $valid = $user->update($userdata);
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

  public function validate($userdata) {
    $valid = FALSE;
    $check_password = !$this->exists || strlen($userdata['edit-password']) > 0;

    exec( "grep -r -i -o -P -m100  --include \*.json  '.{0,140}" . $userdata['edit-email'] . ".{0,140}' " . $this->env->dir['docroot'] . '/_users', $mail_exists);

    // TODO: check also special characters.
    if ($this->name == '') {
      new Message($this->env, 'The username: ' . $this->name . ' is invalid!', MESSAGE_WARNING);
    }

    elseif ($this->exists && $this->context == USER_ACTION_REGISTER) {
      new Message($this->env, 'Username ' . $this->name . ' already exists!', MESSAGE_WARNING);
    }

    elseif ($mail_exists && $this->email != $this->email) {
      new Message($this->env, 'Email ' . $this->email . ' already exists!', MESSAGE_WARNING);
    }
    elseif(!valid_email($this->email)) {
      new Message($this->env, 'Please enter a valid E-mail address!', MESSAGE_WARNING);
    }
    elseif ($check_password && $this->exists && ($this->password != pwd($userdata['edit-old_password']))) {
      new Message($this->env, 'The old password you entered is not valid. Please try again.', MESSAGE_WARNING);
    }
    elseif ($check_password && strlen($userdata['edit-password']) < USER_PASSWORD_MIN_LENGTH) {
      new Message($this->env, 'Password should be at least ' . USER_PASSWORD_MIN_LENGTH . ' characters long.', MESSAGE_WARNING);
    }
    elseif ($check_password && pwd($userdata['edit-password_rp']) != pwd($userdata['edit-password'])) {
      new Message($this->env, 'Password fields are not the same.', MESSAGE_WARNING);
    }

    else {
      $valid = TRUE;
    }
    return $valid;

  }

  /**
   *
   * Check if user can access a node
   * @return User
   */
  public function checkAccess($node = NULL, $action = NULL) {
    // TODO: bad way to check non-node specific permissions.
    // TODO: URGENT URGENT: security.
    if ($node == NULL) {
      return TRUE;
    }
    $permissions = $node->getPermissions();
    $action = trim(strtolower($action));
    $permission = (string) strtolower(trim($permissions[$action]));
    $has_permissions = array();
    if (isset($action) && isset($permissions[$action]) && $permission == 'anonymous') {
      return TRUE;
    }
    foreach ($permissions as $perm_name => $perm_role) {
      if ($this->hasRole($perm_role)) {
        $has_permissions[$perm_name] = $perm_name;
      }
    }

    if (isset($action)) {
      return isset($has_permissions[$action]);
    }
    else {
      return $has_permissions;
    }
  }

  // TODO: refactor.
  public function renderEditForm($context) {
    $this->context = $context;
    $user_edit_form = $this->wikize(file_get_contents('core/user/tpl/' . $context . '.inc'));
    return $user_edit_form;
  }

  // TODO: refactor.
  public function renderLoginForm() {
    $login_form = $this->wikize(file_get_contents('core/user/tpl/user_login.inc'));
    return $login_form;
  }

  private function wikize($edit_user) {
    preg_match_all('/\[USER\:(.*)\]/', $edit_user, $m);

    foreach ($m[1] as $k => $userpart) {
      switch ($userpart) {
        case 'action':
          $part = $this->context;
          break;
        case 'password':
          break;
        case 'email':
          $part = $this->email;
          break;
        case 'avatar':
          $part = $this->getAvatar();
          break;

        case 'avatar-img':
          $thumb = new Image($this->env, $this->getAvatar(), $this);
          if ($thumb->exists) {
            $part = $thumb->render();
          }
          else {
            $part = 'No avatar uploaded';
          }
          break;

        case 'time':
          $part = $this->getTime();
          break;

        case 'date':
          $part = $this->getDate();
          break;

        case 'name':
          $part = $this->getName();
          break;

        default:
          $part = ($this->getData($userpart) ? $this->getData($userpart) : '');
          // The item in [NODE:item] is unknown.
          break;
      }

      $edit_user = str_replace($m[0][$k], $part, $edit_user);
    }
    return $edit_user;
  }

  // TODO
  public function renderUploadAvatarForm($context) {

  }

  public function getName() {
    return $this->name;
  }

  public function getTime() {
    return 0;
  }

  public function getDate() {
    return date('Y-m-d', $this->getTime());
  }

  private function rebuildSession() {
    $_SESSION['user'] = serialize($this);
  }
}