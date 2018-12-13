<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;
use Quanta\Common\NodeTemplate;
use Quanta\Common\Api;

/**
 * Renders a node as a block that can be embedded elsewhere.
 * @deprecated by [CONTENT] and Grid approach.
 */

class Block extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = NodeFactory::load($this->env, $this->getTarget());

    if (isset($this->attributes['rendered'])) {
      $body = NodeFactory::render($this->env, $this->getTarget());
    }
    else {
      $body = isset($this->attributes['with-title']) ? ('<h2 class="block-title">' . Api::filter_xss($node->getTitle()) . '</h2>' . $node->getBody()) : $node->getBody();
    }

    // Wrap in the inline editor.
    if (empty($this->attributes['editable']) || $this->attributes['editable'] == 'true') {
      $body = NodeTemplate::wrap($this->env, $node, $body);
    }

    // If user can't see the node, don't display it.
    return $node->isForbidden() ? '' : $body;
  }
}
