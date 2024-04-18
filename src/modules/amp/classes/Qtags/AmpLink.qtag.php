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

    $node = NodeFactory::current($this->env);
    $protocol = $this->getAttribute('protocol', 'http');
    $url = empty($this->getAttribute('domain')) ? $this->env->getBaseUrl() : $protocol . '://' . $this->getAttribute('domain');

    return '<link rel="amphtml" href="' . ($url . '/amp/' . $node->getName()) . '">';
  }
}
