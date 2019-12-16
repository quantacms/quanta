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

    $available_params = array(
      "position",
      "mposition",
      "rootOutput",
      "Xoffset",
      "Yoffset",
      "fadeIn",
      "fadeTrans",
      "fadeOut",
      "smoothZoomMove",
      "smoothLensMove",
      "smoothScale",
      "defaultScale",
      "scroll",
      "tint",
      "tintOpacity",
      "lens",
      "lensOpacity",
      "lensShape",
      "lensCollision",
      "lensReverse",
      "openOnSmall",
      "zoomWidth",
      "zoomHeight",
      "sourceClass",
      "loadingClass",
      "lensClass",
      "zoomClass",
      "activeClass",
      "hover",
      "adaptive",
      "adaptiveReverse",
      "title",
      "titleClass",
      "bg");

    foreach ($available_params as $param) {
      if ($this->hasAttribute($param)) {
        $this->html_params[$param] = $this->getAttribute($param);
      }
    }
    return parent::render();
  }
}
