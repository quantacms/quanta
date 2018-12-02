<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;
/**
 * Render a specific permission for a node.
 */
class Permission extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    if ($node->isNew()) {
      $permission = 'inherit';
    }
    else {
      if (!empty($node->getAttributeJSON('permissions')->{$this->attributes['name']})) {
        // Try to fetch the permission from the node, first.
        $permission = $node->getAttributeJSON('permissions')->{$this->attributes['name']};
      }
      else {
        $permission = $node->getPermission($this->attributes['name']);
      }
    }
    return $permission;
  }
}
