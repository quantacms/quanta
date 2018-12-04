<?php
namespace Quanta\Qtags;
/**
 * Renders a random number in a given range.
 */

class Random extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $min = !empty($this->attributes['min']) ? $this->attributes['min'] : 0;
    $max = !empty($this->attributes['max']) ? $this->attributes['max'] : 1000000;
    return rand($min, $max);
  }
}
