<?php
namespace Quanta\Qtags;

/**
 * Encodes the target string into JSON.
 */
class Json extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    return json_encode($this->getTarget());
  }
}
