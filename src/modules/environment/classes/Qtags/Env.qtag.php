<?php
namespace Quanta\Qtags;

use Quanta\Common\User;
use Quanta\Common\UserFactory;

/**
 * Get env data.
 */
class Env extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $key = $this->getAttribute('key');
    if (empty($key)) {
      return '';
    }

    // Only explicitly public environment values may be rendered for non-admins.
    // Deployments can extend this list with QTAG_PUBLIC_ENV_KEYS=a,b,c.
    $public_keys = array('CAPTCHA_SITE_KEY');
    $configured_keys = $this->env->getData('QTAG_PUBLIC_ENV_KEYS');
    if (is_string($configured_keys) && $configured_keys !== '') {
      $public_keys = array_merge($public_keys, array_filter(array_map('trim', explode(',', $configured_keys))));
    }

    if (!in_array($key, $public_keys, TRUE)) {
      $user = UserFactory::current($this->env);
      if (!$user->hasRole(User::ROLE_ADMIN)) {
        return '';
      }
    }

    $value = $this->env->getData($key);
    return is_scalar($value) ? (string) $value : '';
  }
}
