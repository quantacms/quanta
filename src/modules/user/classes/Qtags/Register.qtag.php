<?php
namespace Quanta\Qtags;
use Quanta\Common\UserFactory;
use Quanta\Common\UserAccess;
/**
 * Renders an user sign up / registration link.
 */
class Register extends Link {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public $link_class = array('register-link');

  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $user = UserFactory::current($this->env);
    if (UserAccess::check($this->env, USER_ACTION_REGISTER, array('user' => $user))) {
      if (empty($this->attributes['title']))
      {
        $this->attributes['title'] = t('Sign up');
      }

      return $user->exists ? '' : parent::render();
    }
    else {
      return '';
    }
  }
}
