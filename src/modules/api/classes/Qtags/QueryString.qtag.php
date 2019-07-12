<?php
namespace Quanta\Qtags;
/**
 * Returns a parameter from the Query String.
 */

class QueryString extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    if (!empty($this->getAttribute('name')) && !empty($_REQUEST[$this->getAttribute('name')])) {
      return $_REQUEST[$this->getAttribute('name')];
    }
  }
}
