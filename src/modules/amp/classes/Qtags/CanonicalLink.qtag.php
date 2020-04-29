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
    $protocol = $this->getAttribute('protocol', 'http');
    $url = empty($this->getAttribute('domain')) ? $this->env->getBaseUrl() : $protocol . '://' . $this->getAttribute('domain');
    return '<link rel="canonical" href="' . $url . '/' . $node->getName() . '">';
  }
}
