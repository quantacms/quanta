<?php
namespace Quanta\Qtags;

/**
 * Renders a FontAwesome branded icon.
 */
class FabIcon extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    return '<i class="fab fa-' . $this->getTarget() . '"></i>';
  }
}
