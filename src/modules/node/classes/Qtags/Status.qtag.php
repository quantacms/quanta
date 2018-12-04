<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;

/**
 *
 * Returns the status of a node.
 *
 */
class Status extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   *
   * TODO: make editable.
   */
  public function render() {
    $node = NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $status = $node->getStatus();
    $status_node = NodeFactory::load($this->env, $status);
    return $status_node->getTitle();
  }
}
