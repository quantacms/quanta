<?php
namespace Quanta\Qtags;

/**
 * Renders a simple (translatable) string.
 */

class Text extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return text
   *   The rendered Qtag.
   */
  public function render() {
    $tag = !isset($this->attributes['tag']) ? NULL : $this->attributes['tag'];
    return \Quanta\Common\Localization::translatableText($this->env, $this->getTarget(), $tag);
  }
}
