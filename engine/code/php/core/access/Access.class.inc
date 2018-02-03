<?php

/**
 * This class represents an user in the system.
 * Users in Quanta CMS are just extensions of Node objects,
 * AKA folder + json file inside.
 */
class Access {
  /**
   *
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
   *   Returns true if access check was positive.
   */
  public static function check($env, $action, $vars = array()) {
    /** @var User $user */
    $user = isset($vars['user']) ? $vars['user'] : UserFactory::current($env);

    switch ($action) {
      case NODE_ACTION_DELETE:
      case NODE_ACTION_DELETE_FILE:
      case NODE_ACTION_EDIT:
      case NODE_ACTION_VIEW:
      case NODE_ACTION_ADD:

        // TODO: refactor elsewhere.
        if (isset($vars['node'])) {
          /** @var Node $node */
          $node = $vars['node'];

          // If node doesn't exist, allow no permission to it.
          if (!is_object($node) || !$node->exists) {
            return FALSE;
          }
          $permissions = $node->getPermissions();
          $action = trim(strtolower($action));

          // Conversion to array as of new approach to values.
          if (!is_array($permissions[$action])) {
            $permissions[$action] = array ($permissions[$action]);
          }
          $perm_array = array_flip($permissions[$action]);

          if (isset($action) && isset($perm_array[ROLE_ANONYMOUS])) {
            return TRUE;
          }
          else {
            // Compare the permissions in the node
            foreach ($perm_array as $perm_role => $counter) {
              if ($user->hasRole($perm_role)) {
                return TRUE;
              }
            }
          }
          return FALSE;
        }

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