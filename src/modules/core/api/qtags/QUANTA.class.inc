<?php
namespace Quanta\Qtags;
/**
 * Qtag QUANTA.
 * This Qtag renders a promo link to the Quanta.org website.
 */

class QUANTA extends Qtag {
  /**
   * Check if the current page is in amp.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    return '<span class="powered-by-quanta">Powered by <a href="https://www.quanta.org">Quanta CMS</a></span>';
  }
}
