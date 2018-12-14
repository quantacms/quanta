<?php
namespace Quanta\Qtags;

/**
 * Renders an user sign up / registration link.
 */
class Register extends Link {
  public $link_class = array('register-link');

  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $user = \Quanta\Common\UserFactory::current($this->env);
    if (\Quanta\Common\UserAccess::check(
      $this->env,
      \Quanta\Common\User::USER_ACTION_REGISTER,
      array('user' => $user)
    )) {
      $this->html_body = t('Sign up');
      return $user->exists ? '' : parent::render();
    }
  }
}
