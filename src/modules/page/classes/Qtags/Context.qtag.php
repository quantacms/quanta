<?php
namespace Quanta\Qtags;


/**
 * Renders the current context.
 */
class Context extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    return $_REQUEST['context'];
  }
}
