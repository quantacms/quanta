<?php
namespace Quanta\Common;

/**
 * This class is used to check user access to user actions.
 */
class UserAccess extends Access {
  /**
   * Check if an user can perform a certain action.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @param string $action
   *   The action for which we check access.
   *
   * @param array $vars
   *   Miscellaneous variables.
   *
   * @return boolean
   *   Returns TRUE if access check was positive.
   */
  public static function check(Environment $env, $action, array $vars = array()) {
    $access = new UserAccess($env, $action, $vars);
    return $access->checkAction();
  }

  /**
   * Check if the actor can perform an action.
   *
   * @return bool
   */
  public function checkAction() {
    switch ($this->getAction()) {
      case \Quanta\Common\User::USER_ACTION_REGISTER:
      case \Quanta\Common\User::USER_ACTION_EDIT:
      case \Quanta\Common\User::USER_ACTION_EDIT_OWN:
        // TODO: rework.
        return TRUE;
        break;
    }
    return FALSE;
  }

}