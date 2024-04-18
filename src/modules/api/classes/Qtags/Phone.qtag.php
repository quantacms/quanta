<?php
namespace Quanta\Qtags;
/**
 * Renders an HTML5-formatted Phone number with 'tel:' prefix.
 */
class Phone extends Link {
  public $external = TRUE;
  public $link_body;
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    // Remove all spaces, W3C standard.
    $tel = preg_replace('/\s+/', '', $this->getTarget());
    $this->link_body = $tel;
    $this->destination = htmlspecialchars("tel:" . $tel);
    return parent::render();
  }
}
