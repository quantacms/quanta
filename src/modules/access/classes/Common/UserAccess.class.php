<?php
namespace Quanta\Common;

/**
 * This class is used to check user access to user actions.
 *
 * Unlike NodeAccess, the actor here is never taken from $vars: it is always
 * the user making the request (UserFactory::current()). $vars['user'] names
 * the TARGET of the action, the account being registered or edited. Every
 * caller passes the target under that key, and the target is usually built
 * from request data. If it were also the actor, "may X edit X?" would be
 * asked about the requested account itself and would always pass.
 */
class UserAccess extends Access {

  /**
   * @var User $target
   *   The user account the action is performed on.
   */
  protected $target;

  /**
   * {@inheritdoc}
   */
  public function __construct(Environment &$env, $action, array $vars) {
    $target = isset($vars['user']) ? $vars['user'] : NULL;
    unset($vars['user']);
    parent::__construct($env, $action, $vars);
    $this->actor = UserFactory::current($env);
    $this->target = $target ?? $this->actor;
    $this->vars['user'] = $this->target;
  }

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
   *   Miscellaneous variables. 'user' is the target account of the action
   *   (defaults to the current user); the actor is always the current user.
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
      // A new account can be registered under a name that is not taken.
      case \Quanta\Common\User::USER_ACTION_REGISTER:
        $has_permission = !$this->target->exists;
        break;

      // Editing another account is a node edit on that account's node.
      case \Quanta\Common\User::USER_ACTION_EDIT:
        $has_permission = $this->target->exists
          && NodeAccess::check($this->env, \Quanta\Common\Node::NODE_ACTION_EDIT, array('node' => $this->target));
        break;

      // A logged-in user can edit their own existing account.
      case \Quanta\Common\User::USER_ACTION_EDIT_OWN:
        $has_permission = !$this->actor->isAnonymous()
          && $this->target->exists
          && $this->actor->getName() == $this->target->getName();
        break;
    }
    return $has_permission;
  }
}
