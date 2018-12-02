<?php
namespace Quanta\Qtags;
use Quanta\Common\Message;

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
    return Message::burnMessages(MESSAGE_TYPE_SCREEN);
  }
}
