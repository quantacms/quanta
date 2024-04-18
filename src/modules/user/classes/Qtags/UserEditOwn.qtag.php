<?php
namespace Quanta\Qtags;
use Quanta\Common\UserFactory;
use Quanta\Common\UserAccess;
/**
 * Renders a link to edit an user profile.
 */
class UserEditOwn extends Link {
  public $link_class = array('user-edit-own-link');

  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $user = ($this->getTarget() == NULL) ? UserFactory::current($this->env) : new User($this->env, $this->getTarget());
    $this->html_body = t('Edit your profile');
    if (UserAccess::check($this->env, \Quanta\Common\User::USER_ACTION_EDIT_OWN, array('user' => $user))) {
      return $user->exists ? parent::render() : $this->getTarget();
    }
    else {
      return '';
    }
  }
}
