<?php
namespace Quanta\Qtags;

/**
 * Renders a FontAwesome non-branded icon.
 */
class FasIcon extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    return '<i class="fas fa-' . $this->getTarget() . '"></i>';
  }
}
