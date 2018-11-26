<?php
namespace Quanta\Qtags;
/**
 * Qtag STRING.
 * This Qtag renders a simple (translatable) string.
 */

class STRING extends Qtag {
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
