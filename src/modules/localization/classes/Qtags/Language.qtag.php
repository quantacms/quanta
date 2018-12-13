<?php
namespace Quanta\Qtags;

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
    return \Quanta\Common\Localization::getLanguage($this->env);
  }
}
