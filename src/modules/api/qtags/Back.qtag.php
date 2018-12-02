<?php
namespace Quanta\Qtags;
/**
 * Renders a "back" button link.
 */

class Back extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $title = isset($this->attributes['title']) ? $this->attributes['title'] : t('Back');
    return '<a href="#" onclick="history.back()">' . $title . '</a>';
  }
}
