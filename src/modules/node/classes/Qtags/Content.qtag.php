<?php
namespace Quanta\Qtags;

/**
 *
 * Renders the full rendered content of a node.
 *
 */
class Content extends HtmlTag {
  /**
   * @return string
   *   The rendered Htmltag.
   */
  public function render() {
    // We can't allow an empty target for content, as it would continue looping forever.
    $node = \Quanta\Common\NodeFactory::loadOrCurrent($this->env, $this->getTarget());

    $content = \Quanta\Common\NodeFactory::render($this->env, $node->getName());

    // Inline editing link.
    if (isset($this->attributes['editable'])) {
      $content = \Quanta\Common\NodeTemplate::wrap($this->env, $node, $content);
    }
    $this->html_body = $content;
    return parent::render();
  }
}
