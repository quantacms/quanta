<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;
use Quanta\Common\Api;
use Quanta\Common\NodeTemplate;
/**
 *
 * Renders the teaser of a node.
 *
 */
class Teaser extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $teaser = Api::filter_xss($node->getTeaser());
    if (isset($this->attributes['editable'])) {
      $teaser = NodeTemplate::wrap($this->env, $node, $teaser);
    }
    return $teaser;
  }
}
