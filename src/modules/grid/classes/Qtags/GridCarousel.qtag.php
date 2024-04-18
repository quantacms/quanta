<?php
namespace Quanta\Qtags;

// TODO: fits better the flickity module than the grid one.

/**
 * Creates a carousel that displays its elements in a grid.
 */
class GridCarousel extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $wrapper_html_tag = !empty($this->attributes['wrapper_html_tag']) ? $this->attributes['wrapper_html_tag'] : 'div';
    $wrapper_id = !empty($this->attributes['wrapper_id']) ? $this->attributes['wrapper_id'] : '';
    $wrapper_class = !empty($this->attributes['wrapper_class']) ? $this->attributes['wrapper_class'] : '';
    return  '<' . $wrapper_html_tag . (!empty($wrapper_id) ? ' id="' . $wrapper_id . '"' : '') . (!empty($wrapper_class) ? ' class="' . $wrapper_class . '"' : '') . '>' . $this->getTarget() . '</' . $wrapper_html_tag . '>';
  }
}
