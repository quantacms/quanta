<?php
namespace Quanta\Qtags;

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
    $node = \Quanta\Common\NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $teaser = \Quanta\Common\Api::filter_xss($node->getTeaser());
    if (!empty($teaser)) {
      if (isset($this->attributes['editable'])) {
        $teaser = \Quanta\Common\NodeTemplate::wrap($this->env, $node, $teaser);
      }
      return $teaser;
    }
  }
}
