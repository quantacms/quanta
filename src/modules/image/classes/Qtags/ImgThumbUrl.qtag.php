<?php
namespace Quanta\Qtags;

/**
 * Create a thumbnail / edited version of an image on the fly.
 */
class ImgThumbUrl extends ImgThumb {
  /**
   * Render the Qtag.
   * @deprecated as probably it's a waste to have a core qtag for this.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $this->attributes['url'] = TRUE;
    return parent::render();
  }
}
