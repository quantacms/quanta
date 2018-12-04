<?php
namespace Quanta\Qtags;
/**
 * Render a page's header (<header> tag).
 */
class Header extends Content {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $this->attributes['grid_html_tag'] = 'header';
    $grid_attr = isset($this->attributes['grid']) ? $this->attributes['grid'] : '';
    $this->attributes['grid'] = 'grid ' . $grid_attr;
    return parent::render();
  }
}
