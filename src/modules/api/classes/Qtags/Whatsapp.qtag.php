<?php
namespace Quanta\Qtags;
/**
 * Renders an HTML5-formatted Whatsapp number.
 */
class Whatsapp extends Link {
  public $external = TRUE;
  public $link_body;
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    if($this->getTarget()){
      // Remove all spaces, W3C standard.
      $whatsapp_number = preg_replace('/\s+/', '', $this->getTarget());
      $this->link_body = $whatsapp_number;
      $this->destination = htmlspecialchars("https://wa.me/" . $whatsapp_number);
      $this->setType(Link::LINK_EXTERNAL);
      $this->link_target = htmlspecialchars("https://wa.me/" . $whatsapp_number);
      if($this->getAttribute("icon")){
        $icon_class = $this->getAttribute("icon");
        $this->html_body = "<i class=\"{$icon_class}\"></i>";
      };
      return parent::render();
    }
  }
}
