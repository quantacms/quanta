<?php
namespace Quanta\Qtags;
/**
 * Renders an HTML5-formatted Email address with 'mailto:' prefix.
 */

class Email extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $string = '';
    if (valid_email($this->getTarget())) {
      $title = (isset($this->attributes['title']) ? $this->attributes['title'] : $this->getTarget());
      $string = '<a class="mail" href="mailto:' . $this->getTarget() . '">' . $title  . '</a>';
    }
    return $string;
  }
}
