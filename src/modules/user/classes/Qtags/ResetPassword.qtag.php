<?php
namespace Quanta\Qtags;
/**
 * Renders an user reset password link.
 */
class ResetPassword extends Link {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {

    $this->link_class = array('reset-password-link');
    $this->html_body = t('Reset password');
    return parent::render();

  }
}
