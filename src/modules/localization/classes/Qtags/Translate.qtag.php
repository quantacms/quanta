<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;

/**
 * Renders a translate link for a Node.
 */
class Translate extends Edit {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = NodeFactory::load($this->env, $this->getTarget());
    if (!$node->exists) {
      return '';
    }
    $this->language = $node->getName();
    $this->link_body = $node->getTitle();
    return parent::render();
  }
}
