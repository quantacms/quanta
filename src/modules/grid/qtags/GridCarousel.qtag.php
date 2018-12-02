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
    if (Amp::isActive($this->env)) {
      // Google AMP carousel.
      $this->attributes['module'] = 'grid';
      $this->attributes['tpl'] = 'grid-carousel-amp';
      $this->attributes['carousel_width'] = '400';
      $this->attributes['carousel_height'] = '260';
      $this->attributes['carousel_autoplay'] = 'true';
      $this->attributes['carousel_type'] = 'slides';
      $carousel = new AMP_CAROUSEL($this->env, $this->getTarget(), $this->attributes);

    } else {
      // Classic flickity carousel.
      $this->attributes['module'] = 'grid';
      $this->attributes['tpl'] = 'grid-carousel';
      $this->attributes['flickity_theme'] = 'actionbutton';
      $this->attributes['pageDots'] = 'true';
      $this->attributes['editable'] = 'false';
      $carousel = new CAROUSEL($this->env, $this->getTarget(), $this->attributes);
    }
    return $carousel->render();
  }
}
