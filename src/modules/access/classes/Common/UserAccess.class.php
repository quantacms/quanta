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
    // TODO: add hooks.
    $has_permission = FALSE;

    // Check access for a specific action.
    switch ($this->getAction()) {
      // By default an user can "register" a new account if he's anonymous / unlogged.
      case \Quanta\Common\User::USER_ACTION_REGISTER:
        $has_permission = !$this->actor->exists;
        break;
        // To see if an user can edit another, use node permissions.
      case \Quanta\Common\User::USER_ACTION_EDIT:
        $has_permission = NodeAccess::check($this->env, \Quanta\Common\Node::NODE_ACTION_EDIT, array('node' => $this->vars['edit_user']));
        break;
        // By default an user can edit his own profile.
      case \Quanta\Common\User::USER_ACTION_EDIT_OWN:
        $has_permission = $this->actor->getName() == $this->vars['user']->getName();
        break;
    }
    return $has_permission;
  }
}
