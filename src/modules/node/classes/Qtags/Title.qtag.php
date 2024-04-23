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
    $title = Api::string_normalize(Api::filter_xss($node->getTitle()));
    return $title;
  }
}
