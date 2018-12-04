<?php
namespace Quanta\Qtags;
/**
 * Renders an HTML5-formatted Phone number with 'tel:' prefix.
 */

class PHONE extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    // Remove all spaces, W3C standard.
    $tel = preg_replace('/\s+/', '', $this->getTarget());
    // TODO: make as an extension of LINK.
    return '<a class="phone ' . $this->attributes['phone_class'] . '" href="tel:' . $tel . '">' . $this->getTarget() . '</a>';
  }
}
