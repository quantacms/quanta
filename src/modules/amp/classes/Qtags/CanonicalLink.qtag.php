<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;
/**
 * Renders a link to the Canonical version of a node.
 */
class CanonicalLink extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = NodeFactory::current($this->env);
    return '<link rel="canonical" href="' . ($this->env->getBaseUrl() . '/' . $node->getName()) . '">';
  }
}
