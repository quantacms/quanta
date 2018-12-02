<?php
namespace Quanta\Qtags;
/**
 * Renders a target URL, or the current site URL.
 */

class Url extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    if (empty($target)) {
      $target = $this->env->getRequestedPath();
    }
    // TODO: handle "amp" in URL contruction.
    return $this->env->getBaseUrl() . '/' . $target;
  }
}
