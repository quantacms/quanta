<?php
namespace Quanta\Qtags;
use DateTime;
/**
 * Renders a formattable Date.
 */

class Date extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $date = new DateTime($this->getTarget());
    if (isset($this->attributes['format'])) {
      $formatted_date = $date->format($this->attributes['format']);
    }
    else {
      $formatted_date = $this->getTarget();
    }
    return $formatted_date;
  }
}
