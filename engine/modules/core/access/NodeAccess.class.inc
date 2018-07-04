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
    $can_access = $access->checkAction();
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
      case NODE_ACTION_DELETE:
      case NODE_ACTION_DELETE_FILE:
      case NODE_ACTION_EDIT:
      case NODE_ACTION_EDIT_TPL:
      case NODE_ACTION_VIEW:
      case NODE_ACTION_ADD:
        $permissions = $this->node->getPermissions();

        // If node doesn't exist, allow no permission to it.
        if ((!is_object($this->node) || !$this->node->exists) && $this->getAction() != NODE_ACTION_ADD) {
          new Message($this->env, 'Error: trying to perform the ' . $this->getAction() . ' action on a non existing node ' . $this->node->name . '.', MESSAGE_WARNING);
        }
        else {
          // Conversion to array as of new approach to values.
          if (!is_array($permissions[$this->getAction()])) {
            $permissions[$this->getAction()] = array($permissions[$this->getAction()]); 
          }         
          $perm_array = array_flip($permissions[$this->getAction()]);

          // If allowed role is anonymous always grant access.
          if (!empty($this->getAction()) && isset($perm_array[ROLE_ANONYMOUS])) {
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
        new Message($this->env, 'Error: the action ' . $this->getAction() . ' is unknown.', MESSAGE_ERROR);
    }

    return $can_access;
  }
}
