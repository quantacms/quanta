<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;
/**
 * Renders an AMP carousel of nodes.
 */
class AmpLink extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = NodeFactory::current($this->env);
    return '<link rel="amphtml" href="' . ($this->env->getBaseUrl() . '/amp/' . $node->getName()) . '">';
  }
}
