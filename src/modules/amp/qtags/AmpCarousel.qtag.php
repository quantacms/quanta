<?php
namespace Quanta\Qtags;
/**
 * Renders a link to the AMP version of a node.
 */
class AmpCarousel extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {

    $module = isset($this->attributes['module']) ? $this->attributes['module'] : 'amp';

    // TODO: create a better distinction between the 2 attributes "carousel-type" and "carousel_type"
    if (empty($this->attributes['carousel-type'])) {
      $this->attributes['carousel-type'] = CAROUSEL_DIRS;
    }
    $this->attributes = array('clean' => TRUE, 'class' => 'amp-carousel') + $this->attributes;

    switch ($this->attributes['carousel-type']) {

      case CAROUSEL_DIRS:
        $tpl = isset($this->attributes['tpl']) ? $this->attributes['tpl'] : 'amp-carousel';
        $list = new DirList($this->env, $this->getTarget(), $tpl, $this->attributes, $module);
        break;

      case CAROUSEL_FILES:
        $tpl = isset($this->attributes['tpl']) ? $this->attributes['tpl'] : 'amp-file-carousel';
        $list = new FileList($this->env, $this->getTarget(), $tpl, $this->attributes, $module);
        break;

      default:
        break;
    }

    $carousel_attributes_defaults = array(
      // TODO: Extend to all options.
      // Width must be "auto" with "fixed-height" layout.
      'carousel_width' => '400',
      // 400:225 = 16:9 ratio.
      'carousel_height' => '225',
      // Responsive / fixed-height.
      'carousel_layout' => 'responsive',
      // Slides / carousel.
      'carousel_type' => 'slides',
      // True only for "slides" type.
      'carousel_autoplay' => 'false',
      // Used when autoplay is active.
      'carousel_delay' => '3000',
    );

    $carousel_attributes = array();

    foreach ($carousel_attributes_defaults as $k => $attr) {
      $carousel_attributes[$k] = (isset($this->attributes[$k]) ? $this->attributes[$k] : $attr);
    }

    // Why is this needed? TODO.
    $rand_class = rand(0, 99999999);

    $html = '<amp-carousel class="amp-' . $rand_class . '"';
    $html .= ' width="' . $carousel_attributes['carousel_width'] . '"';
    $html .= ' height="' . $carousel_attributes['carousel_height'] . '"';
    $html .= ' layout="' . $carousel_attributes['carousel_layout'] . '"';
    $html .= ' type="' . $carousel_attributes['carousel_type'] . '"';
    $html .= ($carousel_attributes['carousel_autoplay'] == 'true' ? ' autoplay' : '');
    $html .= ' delay="' . $carousel_attributes['carousel_delay'] . '"';
    $html .= '>' . $list->render() . '</amp-carousel>';

    return $html;
  }
}
