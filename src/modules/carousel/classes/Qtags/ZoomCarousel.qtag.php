<?php
namespace Quanta\Qtags;

/**
 * Create a visual carousel based on a node list.
 * We are using the flickity plugin for rendering the carousel.
 */
class ZoomCarousel extends FileCarousel {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $this->addClass('xzoom-thumbs');
    $this->attributes['tpl'] = 'zoom';
    return parent::render();
  }
}
