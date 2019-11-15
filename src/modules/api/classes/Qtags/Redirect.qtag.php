<?php
namespace Quanta\Qtags;
/**
 * Redirects the user to a given page.
 */

class Redirect extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    \Quanta\Common\Api::redirect($this->getTarget());
  }
}
