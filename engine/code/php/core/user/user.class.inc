<?php
define("USER_ANONYMOUS", "anonymous");
define("ROLE_ANONYMOUS", "anonymous");

/**
 * This class represents an user in the system.
 */
class User {
  public $name;
  public $roles = array();
  public $exists;
  private $env;
  private $dir;
  private $password;
  private $data = array();

  // Construct the Node.
  public function __construct(&$env, $name = USER_ANONYMOUS, $roles = array(ROLE_ANONYMOUS => ROLE_ANONYMOUS)) {
    $this->env = $env;
    $this->name = $name;
    $this->roles = $roles;
    $this->dir = $this->env->dir['users'] . '/' . strtolower($this->name);
    $this->exists = is_dir($this->dir);
    if ($name != USER_ANONYMOUS && strlen($name) > 0 && $this->exists) {
      $this->data = file($this->dir . '/.userdata');
      foreach ($this->data as $k => $udata) {
        $udata_arr = explode(':', $udata);
        $udata_exp = explode(',', $udata_arr[1]);
        $this->{$udata_arr[0]} = ($udata_arr[0] == 'roles') ? $udata_exp : $udata_arr[1];
      }
    }
  }

  /**
   * Get the current navigating user.
   */
  static function current($env) {
    if (!isset($_SESSION['user'])) {
      $_SESSION['user'] = serialize(new User($env));
    }
    return unserialize($_SESSION['user']);
  }

  private function checkPassword($password) {
    return ($this->password == $password);
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
  }

  public function logIn($password) {
    // If user dir doesn't exist.
    if (!($this->exists)) {
      new Message($this->env, 'This is not a valid username. Please <a class="login-link" href="#">try again</a>', MESSAGE_WARNING, MESSAGE_TYPE_SCREEN);
      new Message($this->env, 'Someone tried to login with wrong username: ' . $this->name, MESSAGE_WARNING, MESSAGE_TYPE_LOG);

    } else {

      if ($this->checkPassword($password)) {
        new Message($this->env, 'Welcome ' . $this->name . '! You logged in', MESSAGE_CONFIRM, MESSAGE_TYPE_SCREEN);
        new Message($this->env, 'User ' . $this->name . ' logged in', MESSAGE_CONFIRM, MESSAGE_TYPE_LOG);
        $this->roles += array('logged' => 'logged');
        unset($this->password);
        $_SESSION['user'] = serialize($this);
      } else {
        new Message($this->env, 'Wrong username or password. Please <a class="login-link" href="#">try again</a>', MESSAGE_WARNING, MESSAGE_TYPE_SCREEN);
        new Message($this->env, 'User ' . $this->name . ' tried to login with wrong username or password', MESSAGE_WARNING, MESSAGE_TYPE_LOG);
      }
    }
  }

  /**
   *
   * Check if user can access a node
   * @return User
   */
  public function checkAccess($node, $action = NULL) {

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
}