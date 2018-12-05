<?php
namespace Quanta\Qtags;

/**
 * Renders active system messages (errors, warnings, etc.).
 */
class Messages extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    return \Quanta\Common\Message::burnMessages(\Quanta\Common\Message::MESSAGE_TYPE_SCREEN);
  }
}
