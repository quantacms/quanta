<?php
namespace Quanta\Qtags;

/**
 *
 * Renders the full rendered content of a node, without any additional wrappers.
 *
 */
class Render extends QTag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    // We can't allow an empty target for content, as it would continue looping forever.
    $node = \Quanta\Common\NodeFactory::loadOrCurrent($this->env, $this->getTarget());

    $content = \Quanta\Common\NodeFactory::render($this->env, $node->getName());

    return $content;
  }
}
