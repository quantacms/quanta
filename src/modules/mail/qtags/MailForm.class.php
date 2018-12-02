<?php
namespace Quanta\Qtags;

/**
 * Renders a mail / contact form.
 */
class MailForm extends Form {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    if (!isset($this->attributes['ok_message'])) {
      $this->attributes['ok_message'] = 'Your mail has been sent. Thanks!';
    }
    $this->attributes['type'] = 'mailform';
    return parent::render();
  }
}
