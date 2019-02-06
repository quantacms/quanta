<?php
namespace Quanta\Qtags;
/**
 * Latitude of a Node - fetched from JSON.
 */
class Latitude extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = \Quanta\Common\NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    return $node->getAttributeJSON('latitude');
  }
}
