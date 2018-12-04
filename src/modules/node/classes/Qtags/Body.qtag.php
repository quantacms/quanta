<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;
use Quanta\Common\NodeTemplate;
/**
 * Renders the body of a node.
 */
class Body extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    // TODO: breaks the HTML, but we definitely need a xss filter for this tag.
    // $body = Api::filter_xss($node->getBody());
    $body = $node->getBody();
    if (isset($attributes['editable'])) {
      $body = NodeTemplate::wrap($this->env, $node, $body);
    }
    return $body;
  }
}
