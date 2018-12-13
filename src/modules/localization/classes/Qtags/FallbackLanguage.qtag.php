<?php
namespace Quanta\Qtags;
use Quanta\Common\Localization;
/**
 * Returns the current fallback language.
 */
class FallbackLanguage extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    return Localization::getFallbackLanguage($this->env);
  }
}
