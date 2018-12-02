<?php
namespace Quanta\Qtags;
/**
 * Render amp version of a sidebar item.
 */
class AmpSidebar extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {

    $sidebar_attributes_defaults = array(
      'sidebar_side' => 'right',
    );

    $sidebar_attributes = array();
    foreach ($sidebar_attributes_defaults as $k => $attr) {
      $sidebar_attributes[$k] = (isset($this->attributes[$k]) ? $this->attributes[$k] : $attr);
    }

    $rand_class = rand(0, 99999999);
    // TODO: move in TPL file.
    $html = '<amp-sidebar id="' . $this->attributes['sidebar_id'] . '"';
    $html .= ' class="amp-' . $rand_class . '"';
    $html .= ' layout="nodisplay"';
    $html .= ' side="' . $sidebar_attributes['sidebar_side'] . '"';
    $html .= '>';
    $html .= '<button class="amp-close-sidebar" on="tap:' . $this->attributes['sidebar_id'] . '.close" role="button" tabindex="0"><i class="fa fa-times"></i></button>';
    $html .= $this->getTarget();
    $html .= '</amp-sidebar>';

    return $html;
  }
}
