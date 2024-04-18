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
    $this->setAttribute('node', $node->getName());
    $this->setTarget($node->getThumbnail());
    $html = parent::render();
    if (empty($this->getAttribute('link')) || $this->getAttribute('link') != 'false') {
      $link = new Link($this->env, $this->getAttributes(), $node->getName());
      $link->destination = '/' . (!empty($this->getAttribute('href')) ? $this->getAttribute('href') :  $node->getName());
      $link->setHtmlBody($html);
      $html = $link->render();
    }
    return $html;
  }
}
