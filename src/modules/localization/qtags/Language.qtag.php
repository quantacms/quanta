<?php
namespace Quanta\Qtags;
use Quanta\Common\Localization;

/**
 * Returns the current language.
 */
class Language extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    return Localization::getLanguage($this->env);
  }
}
