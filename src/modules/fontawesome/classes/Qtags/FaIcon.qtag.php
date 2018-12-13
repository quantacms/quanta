<?php
namespace Quanta\Qtags;

/**
 * Renders a FontAwesome icon.
 */
class FaIcon extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    return '<i class="fa fa-' . $this->getTarget() . '"></i>';
  }
}
