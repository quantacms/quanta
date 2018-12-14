<?php
namespace Quanta\Qtags;
use Quanta\Common\User;
use Quanta\Common\UserFactory;

/**
 * Renders an user sign up / registration link.
 */
class Login extends Link {

  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $user = UserFactory::current($this->env);
    if ($user->exists) {
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
