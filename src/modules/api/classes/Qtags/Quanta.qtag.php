<?php
namespace Quanta\Qtags;
/**
 * Renders a promo link to the Quanta.org website.
 */
class Quanta extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    return '<span class="powered-by-quanta">Powered by <a target="_blank" href="https://www.quanta.org">Quanta CMS</a></span>';
  }
}
