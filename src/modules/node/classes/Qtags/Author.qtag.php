<?php
namespace Quanta\Qtags;

/**
 * Retrieves the Author of a node.
 */
class Author extends Link {
  /**
   * Renders the author of a node as an user link.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = \Quanta\Common\NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $this->setTarget($node->getAuthor());
    return parent::render();
  }
}
