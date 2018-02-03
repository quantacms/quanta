<?php
// TODO: redo the crap!
/**
 * Implements qtag USERATTRIBUTE.
 *
 * Returns an user attribute of a given user.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_USERATTRIBUTE($env, $target, $attributes) {
  $user = ($target == NULL) ? UserFactory::current($env) : new User($env, $target);

  switch ($attributes['name']) {
    case 'name':
      $string = $user->getName();
      break;

    default:
      $string = '';
      break;
  }
  return $string;
}

/**
 * Implements qtag LOGIN.
 *
 * Renders an user login link.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_LOGIN($env, $target, $attributes) {
  $user = UserFactory::current($env);
  return $user->exists ? '<a href="#" class="logout-link">Logout</a>' : '<a href="#" class="login-link">Login</a>';
}

/**
 * Implements qtag REGISTER.
 *
 * Renders an user sign up / registration link.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_REGISTER($env, $target, $attributes) {
  $user = UserFactory::current($env);
  return $user->exists ? '' : '<a href="#" class="register-link">Sign up</a>';
}
