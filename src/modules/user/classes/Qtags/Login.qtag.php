<?php
namespace Quanta\Qtags;
/**
 * Renders an user sign up / registration link.
 */
class Login extends Link {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {

    $user = \Quanta\Common\UserFactory::current($this->env);

    if ($user->exists && $user->name != \Quanta\Common\User::USER_ANONYMOUS) {
      $this->link_class = array('logout-link');
      $this->html_body = t('Logout');
    }
    else {
      $this->link_class = array('login-link');
      $this->html_body = t('Login');
    }

    return parent::render();
  }
}
