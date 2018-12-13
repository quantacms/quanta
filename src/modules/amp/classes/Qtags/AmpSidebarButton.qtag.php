<?php
namespace Quanta\Qtags;
use Quanta\Common\Amp;
/**
 * Render amp version of a sidebar item.
 */
class AmpSidebarButton extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {

    $html = '';

    // TODO: find a better way to filter AMP
    if (Amp::isActive($env)) {
      $sidebar_button_attributes_defaults = array(
        'sidebar_button_action' => 'toggle',
      );

      $sidebar_button_attributes = array();
      foreach ($sidebar_button_attributes_defaults as $k => $attr) {
        $sidebar_button_attributes[$k] = (isset($attributes[$k]) ? $attributes[$k] : $attr);
      }

      $rand_class = rand(0, 99999999);
      $html = '<button' . (empty($attributes['sidebar_button_id']) ? '' : ' id="' . $attributes['sidebar_button_id'] . '"');
      $html .= ' class="amp-' . $rand_class . '"';
      $html .= ' on="tap:' . $this->getTarget() . '.' . $sidebar_button_attributes['sidebar_button_action'] . '"';
      $html .= '>';
      $html .= $attributes['sidebar_button_html'];
      $html .= '</button>';
    }

    return $html;
  }
}
