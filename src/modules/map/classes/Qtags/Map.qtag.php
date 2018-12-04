<?php
namespace Quanta\Qtags;

/**
 * Renders a Google Map.
 * TODO: enable other map types.
 */
class Map extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    if (!isset($this->attributes['key'])) {
      return 'invalid key.';
    }
    return '<iframe width="100%" height="100%" frameborder="0" style="border:0" src="https://www.google.com/maps/embed/v1/place?key=' . $this->attributes['key'] . '&q=' . $this->getTarget() . '" allowfullscreen></iframe>';
  }
}
