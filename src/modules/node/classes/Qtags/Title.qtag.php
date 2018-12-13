<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;
use Quanta\Common\Api;
use Quanta\Common\NodeTemplate;
/**
 *
 * Renders the title of a node.
 *
 */
class Title extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $title = Api::filter_xss($node->getTitle());
    if (isset($this->attributes['editable'])) {
      $title = NodeTemplate::wrap($this->env, $node, $title);
    }
    return $title;
  }
}
