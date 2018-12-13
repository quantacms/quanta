<?php
namespace Quanta\Qtags;
use Quanta\Common\Doctor;

/**
 * Returns the timestamp of the last time doctor ran.
 */
class DoctorTimestamp extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    return Doctor::timestamp($this->env);
  }
}
