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
    $thumbnail = $node->getThumbnail();
    $fallback = $this->getAttribute('fallback');
    $target = $thumbnail;
    if (! $thumbnail && !empty($fallback)) {
      if ($fallback === 'first_image') {
        $this->attributes['file_types'] = 'image';
        $this->attributes['clean'] = true;
        $filelist = new \Quanta\Common\FileList($this->env, $this->getTarget(), null, $this->attributes, 'list');

        // make sure it is image
        foreach ($filelist->getItems() as $file) {
          if ($file->type === 'image') {
            $target = $file->getName();
            break;
          }
        }
      } else {
        $target = $fallback;
      }
    }
    $this->setTarget($target);
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
