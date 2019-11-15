<?php
namespace Quanta\Qtags;

/**
 * Deprecated - see FAS_ICON and FAB_ICON to Render a FontAwesome icon.
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
