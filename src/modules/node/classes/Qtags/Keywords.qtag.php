<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;
use Quanta\Common\Api;
use Quanta\Common\NodeTemplate;
/**
 *
 * Renders the keywords of a node.
 *
 */
class Keywords extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    return $node->getKeywords();
  }
}
