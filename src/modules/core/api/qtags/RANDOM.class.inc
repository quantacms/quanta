<?php
namespace Quanta\Qtags;
/**
 * Qtag RANDOM.
 *
 * Renders a random number in a given range.
 */

class RANDOM extends Qtag {
  /**
   * Check if the current page is in amp.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $min = !empty($this->getData('min')) ? $this->getData('min') : 0;
    $max = !empty($this->getData('max')) ? $this->getData('max') : 1000000;
    return rand($min, $max);
  }
}
