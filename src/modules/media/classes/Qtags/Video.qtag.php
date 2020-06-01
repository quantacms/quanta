<?php
namespace Quanta\Qtags;

/**
 * Render a playable audio file.
 */
class Audio extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    return '<audio src="' . $this->getTarget() . '" preload="auto" controls><p>Your browser does not support the audio element.</p></audio>';
  }
}
