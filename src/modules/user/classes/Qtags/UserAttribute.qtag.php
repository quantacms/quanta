<?php
namespace Quanta\Qtags;
use Quanta\Common\UserFactory;
/**
 * Renders an user edit link.
 */
class UserAttribute extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $user = ($this->getTarget() == NULL) ? UserFactory::current($this->env) : new User($this->env, $this->getTarget());
    switch ($this->attributes['name']) {
      // User's login name.
      case 'username':
        $string = $user->getName();
        break;
      // User's last name.
      case 'last_name':
        $string = $user->getLastName();
        break;
      // User's email.
      case 'email':
        $string = $user->getEmail();
        break;

      // User's first name.
      case 'first_name':
        $string = $user->getFirstName();
        break;

      default:
        $string = NULL;
        break;
    }
    return $string;
  }
}
