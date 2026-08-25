<?php
namespace Quanta\Common;

/**
 * This class is used to check user access to node actions.
 */
class NodeAccess extends Access implements \Quanta\Common\Cacheable  {
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
    static $access_checked;

    // Static cache of access controls.
    if (empty($access_checked)) {
      $access_checked = array();
    }
    // make the duplicate action as add action in permissions
    if($action == \Quanta\Common\Node::NODE_ACTION_DUPLICATE){
      $action = \Quanta\Common\Node::NODE_ACTION_ADD;
    }
    elseif($action == \Quanta\Common\Node::NODE_ACTION_CHANGE_AUTHOR){
      $action = \Quanta\Common\Node::NODE_ACTION_EDIT;
    }

    // The verdict depends on WHO is asking, so the actor belongs in the key.
    // Access::__construct() accepts an explicit $vars['user'] and falls back
    // to the current user, so two checks for the same node and action can
    // legitimately concern two different users; keyed on node and action
    // alone, the second would be answered with the first one's verdict.
    // UserFactory::current() memoises statically, so this costs nothing.
    $actor = isset($vars['user']) ? $vars['user'] : UserFactory::current($env);
    $actor_name = $actor->getName();
    $node_name = $vars['node']->getName();

    if (!isset($access_checked[$action][$actor_name][$node_name])) {
      $access = new NodeAccess($env, $action, $vars);
      $can_access = $access->checkAction();
      $access_checked[$action][$actor_name][$node_name] = $can_access;
    }
    else {
      $can_access = $access_checked[$action][$actor_name][$node_name];
    }
    return $can_access;

  }

  /**
   * Check if the actor can perform an action.
   *
   * @return bool
   */
  public function checkAction() {
    $cached = \Quanta\Common\Cache::get($this->env, 'access', $this->cacheTag());
    if (!empty($cached)) {
      $can_access = $cached;
    }

    else {
      $can_access = FALSE;

      switch ($this->getAction()) {

        case \Quanta\Common\Node::NODE_ACTION_DELETE:
        case \Quanta\Common\Node::NODE_ACTION_DELETE_FILE:
        case \Quanta\Common\Node::NODE_ACTION_EDIT:
        case \Quanta\Common\Node::NODE_ACTION_VIEW:
        case \Quanta\Common\Node::NODE_ACTION_ADD:
        case \Quanta\Common\Node::NODE_ACTION_DUPLICATE:
  
          $node_is_object = is_object($this->node);

          // If node doesn't exist, allow no permission to it.
          if ((!$node_is_object || !$this->node->exists) && $this->getAction() != \Quanta\Common\Node::NODE_ACTION_ADD) {
            new Message($this->env,
              t('Error: trying to perform the !action action on a non existing node !node.', array(
                '!node' => $node_is_object ? $this->node->name : '(none)',
                '!action' => $this->getAction())),
              \Quanta\Common\Message::MESSAGE_WARNING
            );
          } elseif ($node_is_object) {
            // Permissions are read inside the guard, not before it: the guard
            // is there because $this->node may not be an object, and
            // getPermissions() on a non-object is a fatal. A non-object node
            // denies access instead of ending the request.
            $permissions = $this->node->getPermissions();

            // Conversion to array as of new approach to values.
            if (!is_array($permissions[$this->getAction()])) {
              $permissions[$this->getAction()] = array($permissions[$this->getAction()]);
            }
            $perm_array = array_flip($permissions[$this->getAction()]);

            // If allowed role is anonymous always grant access.
            if (!empty($this->getAction()) && isset($perm_array[\Quanta\Common\User::ROLE_ANONYMOUS])) {
              $can_access = TRUE;
            } else {
              // Compare the permissions in the node
              foreach ($perm_array as $perm_role => $counter) {
                if ($this->actor->hasRole($perm_role)) {
                  $can_access = TRUE;
                }
                // "Self" means the user has the permission if he's the same
                // as the node (nodes can be users) or if any node in his lineage
                // is the same as the node.
                elseif ($perm_role == 'author') {
                  $can_access = (
                    $this->actor->getName() == $this->node->getAuthor()
                  );
                }
                // "Self" means the user has the permission if he's the same
                // as the node (nodes can be users) or if any node in his lineage
                // is the same as the node.
                elseif ($perm_role == 'self') {
                  $can_access = (
                    ($this->actor->getName() == $this->node->getName()) ||
                    ($this->node->hasParent($this->actor->getName()))
                  );
                }

                if($can_access){
                  break;
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
    }
    \Quanta\Common\Cache::set($this->env, 'access', $this->cacheTag(), $can_access);
    return $can_access;
  }

  public function cacheTag() {
    static $hashed = array();

    $nodeName = json_encode($this->node->name);
    $accessType = json_encode($this->getAction());
    // The actor is part of the identity of an access verdict. Without it this
    // request-scoped cache answers "can this user edit X?" with whatever
    // verdict was reached for the previous user to ask about X.
    // @see NodeAccess::check()
    $actorName = json_encode(is_object($this->actor) ? $this->actor->getName() : '');
    $combinedString = 'access_' . $nodeName . '_' . $accessType . '_' . $actorName;

    if (!isset($hashed[$combinedString])) {
      // xxh64, not crc32: this hash keys an ACCESS VERDICT, so a collision
      // hands one node's answer to another node's question. A 32-bit space is
      // not an acceptable place for that to be decided, and xxh64 is in the
      // same speed class.
      $hash = hash('xxh64', $combinedString);
      $hashed[$combinedString] = $hash;
    } else {
      $hash = $hashed[$combinedString];
    }

    return $hash;
  }

}
