<?php
namespace Quanta\Qtags;
/**
 * Renders a "back" button link.
 */

class Back extends Link {
  protected $html_params = array("onclick" => "history.back()");
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $this->html_body = t('Back');

    return parent::render();
  }
}
