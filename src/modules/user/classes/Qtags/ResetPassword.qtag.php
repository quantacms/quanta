<?php
namespace Quanta\Qtags;
/**
 * Renders an user reset password.
 */
class ResetPassword extends Link {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $this->link_class = array('reset-password-link');
    $title = $this->getAttribute('title') ? $this->getAttribute('title') : t('Reset password');
    $this->html_body = $title;
    return parent::render();
  }
}
