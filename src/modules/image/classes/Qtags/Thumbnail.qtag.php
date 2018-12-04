<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;

/**
 * Renders an image.
 */
class Thumbnail extends ImgThumb {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $this->setTarget($node->getThumbnail());
    $this->attributes['link'] = $node->getName();
    $this->attributes['node'] = $node->getName();
    return parent::render();
  }
}
