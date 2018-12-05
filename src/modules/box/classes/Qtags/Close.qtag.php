<?php
namespace Quanta\Qtags;

/**
 * Renders a "CLOSE" button that, once clicked, destroys a target HTML div via jQuery.
 */

class Close extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $close_title = empty($this->attributes['title']) ? t('X Close') : $this->attributes['title'];
    return '<a class="close-button" href="#' . $this->getTarget() . '">' . $close_title . '</a>';

  }
}
