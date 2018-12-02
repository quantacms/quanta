<?php
namespace Quanta\Qtags;
use Quanta\Common\UserFactory;

/**
 * Renders an user edit link.
 */
class UserEdit extends Link {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $user = ($this->getTarget() == NULL) ? UserFactory::current($this->env) : new User($this->env, $this->getTarget());
    switch ($this->attributes['name']) {
      // User's login name.
      case 'username':
        $this->html = $user->getName();
        break;
      // User's last name.
      case 'last_name':
        $this->html = $user->getLastName();
        break;
      // User's email.
      case 'email':
        $this->html = $user->getEmail();
        break;

      // User's first name.
      case 'first_name':
        $this->html = $user->getFirstName();
        break;

      default:
        break;
    }
    return $this->html;
  }
}
