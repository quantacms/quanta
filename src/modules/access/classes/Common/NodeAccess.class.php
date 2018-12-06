<?php
namespace Quanta\Common;

/**
 * This class is used to check user access to node actions.
 */
class NodeAccess extends Access {
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
  public static function check($env, $action, $vars = array()) {
    static $access_checked;

    // Static cache of access controls.
    if (empty($access_checked)) {
      $access_checked = array();
    }

    if (!isset($access_checked[$action][$vars['node']->getName()])) {
      $access = new NodeAccess($env, $action, $vars);
      $can_access = $access->checkAction();
      $access_checked[$action][$vars['node']->getName()] = $can_access;
    }
    else {
      $can_access = $access_checked[$action][$vars['node']->getName()];
    }
    return $can_access;

  }

  /**
   * Check if the actor can perform an action.
   *
   * @return bool
   */
  public function checkAction() {
    $can_access = FALSE;

    switch ($this->getAction()) {
      case \Quanta\Common\Node::NODE_ACTION_DELETE:
      case \Quanta\Common\Node::NODE_ACTION_DELETE_FILE:
      case \Quanta\Common\Node::NODE_ACTION_EDIT:
      case \Quanta\Common\Node::NODE_ACTION_VIEW:
      case \Quanta\Common\Node::NODE_ACTION_ADD:

        $permissions = $this->node->getPermissions();

        // If node doesn't exist, allow no permission to it.
        if ((!is_object($this->node) || !$this->node->exists) && $this->getAction() != \Quanta\Common\Node::NODE_ACTION_ADD) {
          new Message($this->env,
            t('Error: trying to perform the !action action on a non existing node !node.', array('!node' => $this->node->name,
              '!action' => $this->getAction())),
            \Quanta\Common\Message::MESSAGE_WARNING
          );
        }
        else {
          // Conversion to array as of new approach to values.
          if (!is_array($permissions[$this->getAction()])) {
            $permissions[$this->getAction()] = array($permissions[$this->getAction()]); 
          }         
          $perm_array = array_flip($permissions[$this->getAction()]);

          // If allowed role is anonymous always grant access.
          if (!empty($this->getAction()) && isset($perm_array[\Quanta\Common\User::ROLE_ANONYMOUS])) {
            $can_access = TRUE;
          }
          else {
            // Compare the permissions in the node
            foreach ($perm_array as $perm_role => $counter) {
              if ($this->actor->hasRole($perm_role)) {
                $can_access = TRUE;
              }
            }
          }
        } 
        break;

      default:
        new Message($this->env,
          t('Error: the action !action is unknown.', array('!action' => $this->getAction())),
          \Quanta\Common\Message::MESSAGE_ERROR
        );
    }

    return $can_access;
  }
}
