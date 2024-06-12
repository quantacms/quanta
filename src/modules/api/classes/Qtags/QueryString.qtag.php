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
    if($this->getAttribute('JSON') && !empty($this->getAttribute('name')) && !empty($this->getAttribute('data'))){
      $request_data = json_decode($_REQUEST[$this->getAttribute('name')]);
      return $request_data->{$this->getAttribute('data')};
    }
    elseif (!empty($this->getAttribute('name')) && !empty($_REQUEST[$this->getAttribute('name')])) {
      return $_REQUEST[$this->getAttribute('name')];
    }
  }
}
