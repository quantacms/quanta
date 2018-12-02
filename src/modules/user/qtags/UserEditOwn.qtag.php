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
    if (UserAccess::check($this->env, USER_ACTION_EDIT, array('user' => $user))) {
        if (!isset($this->attributes['title'])) {
          $this->attributes['title'] = t('Edit your profile');
        }

      return $user->exists ? parent::render() : $this->getTarget();
    }
    else {
      return '';
    }
    return $this->html;
  }
}
