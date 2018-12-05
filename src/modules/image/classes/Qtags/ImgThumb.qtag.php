<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;
use Quanta\Common\Image;

/**
 * Create a thumbnail / edited version of an image on the fly.
 */
class ImgThumb extends Img {
  protected $manipulate = TRUE;
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    return parent::render();
  }
}
