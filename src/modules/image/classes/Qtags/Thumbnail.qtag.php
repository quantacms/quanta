<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;

/**
 * Renders an image.
 */
class Thumbnail extends Link {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $this->setAttribute('node', $node->getName());
    $this->setTarget($node->getThumbnail());
    $img = new ImgThumb($this->env, $this->getAttributes(), $this->getTarget());
    $this->destination = $node->getName();
    $this->html_body = $img->render();
    return parent::render();
  }
}
