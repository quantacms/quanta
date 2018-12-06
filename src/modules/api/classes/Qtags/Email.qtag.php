<?php
namespace Quanta\Qtags;
/**
 * Renders an HTML5-formatted Email address with 'mailto:' prefix.
 */
class Email extends Link {
  public $external = TRUE;
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    if (\Quanta\Common\Api::valid_email($this->getTarget())) {
      $this->link_body = (isset($this->attributes['title']) ? $this->attributes['title'] : $this->getTarget());
      $this->destination =  "mailto&colon;" . $this->getTarget();
    }
    return parent::render();
  }
}
