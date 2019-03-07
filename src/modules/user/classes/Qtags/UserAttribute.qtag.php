<?php
namespace Quanta\Qtags;
/**
 * Renders an user edit link.
 */
class UserAttribute extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $user = ($this->getTarget() == NULL) ? \Quanta\Common\UserFactory::current($this->env) : new \Quanta\Common\User($this->env, $this->getTarget());

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
      // User's full name (= title).
      case 'title':
        $string = $user->getTitle();
        break;
      default:
        $string = $user->json->{$this->attributes['name']};
        break;
    }
    return $string;
  }
}
