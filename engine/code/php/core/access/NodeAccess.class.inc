<?php
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
    $access = new NodeAccess($env, $action, $vars);
    return $access->checkAction();
  }

  /**
   * Check if the actor can perform an action.
   *
   * @return bool
   */
  public function checkAction() {
    switch ($this->getAction()) {
      case NODE_ACTION_DELETE:
      case NODE_ACTION_DELETE_FILE:
      case NODE_ACTION_EDIT:
      case NODE_ACTION_VIEW:
      case NODE_ACTION_ADD:

        // If node doesn't exist, allow no permission to it.
        if (!is_object($this->node) || !$this->node->exists) {
          return FALSE;
        }
        $permissions = $this->node->getPermissions();

        // Conversion to array as of new approach to values.
        if (!is_array($permissions[$this->getAction()])) {
          $permissions[$this->getAction()] = array($permissions[$this->getAction()]);
        }
        $perm_array = array_flip($permissions[$this->getAction()]);
        // If allowed role is anonymous always grant access.
        if (!empty($this->getAction()) && isset($perm_array[ROLE_ANONYMOUS])) {
          return TRUE;
        }
        else {
          // Compare the permissions in the node
          foreach ($perm_array as $perm_role => $counter) {
            if ($this->actor->hasRole($perm_role)) {
              return TRUE;
            }
          }
        }
        return FALSE;

        break;

      case USER_ACTION_REGISTER:
      case USER_ACTION_EDIT:
      case USER_ACTION_EDIT_OWN:
        // TODO: rework.
        return TRUE;
        break;
    }
    return FALSE;
  }

}