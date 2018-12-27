<?php
namespace Quanta\Qtags;

/**
 * Create a visual carousel based on a node list.
 * We are using the flickity plugin for rendering the carousel.
 */
class FileCarousel extends Carousel {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $this->attributes['carousel-type'] = \Quanta\Qtags\Carousel::CAROUSEL_FILES;

    return parent::render();
  }
}
