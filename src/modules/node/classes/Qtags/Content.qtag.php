<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;
use Quanta\Common\NodeTemplate;
/**
 *
 * Renders the full rendered content of a node.
 *
 */
class Content extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    // We can't allow an empty target for content, as it would continue looping forever.
    $node = NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $content = NodeFactory::render($this->env, $node->getName());
    // Inline editing link.
    if (isset($this->attributes['editable'])) {
      $content = NodeTemplate::wrap($this->env, $node, $content);
    }
    return $content;
  }
}
