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
  return qtag_ATTRIBUTE($env, $user->getName(), $attributes);
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
  if ($user->exists) {
    $title = isset($attributes['title']) ? $attributes['title'] : 'Logout';
    $link = '[LINK|title=' . $title . '|class=logout-link]';
  }
  else {
    $title = isset($attributes['title']) ? $attributes['title'] : 'Login';
    $link = '[LINK|title=' . $title . '|class=login-link]';
  }
  return $link;
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
  if (UserAccess::check($env, USER_ACTION_REGISTER, array('user' => $user))) {
    $title = isset($attributes['title']) ? $attributes['title'] : 'Sign up';
    return $user->exists ? '' : '[LINK|title=' . $title . '|class=register-link]';
  }
}

/**
 * Implements qtag USER_EDIT.
 *
 * Renders a link to edit an user profile.
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
function qtag_USER_EDIT($env, $target, $attributes) {
  $user = ($target == NULL) ? UserFactory::current($env) : new User($env, $target);
  if (UserAccess::check($env, USER_ACTION_EDIT, array('user' => $user))) {
    $title = isset($attributes['title']) ? $attributes['title'] : 'Edit profile';
    return $user->exists ? '[LINK|title=' . $title . '|class=user-edit-link]' : $target;
  }
}

/**
 * Implements qtag USER_EDIT.
 *
 * Renders a link to edit an user profile.
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
function qtag_USER_EDIT_OWN($env, $target, $attributes) {
  $user = ($target == NULL) ? UserFactory::current($env) : new User($env, $target);
  if (UserAccess::check($env, USER_ACTION_EDIT, array('user' => $user))) {
    $title = isset($attributes['title']) ? $attributes['title'] : 'Edit your profile';
    return $user->exists ? '[LINK|title=' . $title . '|class=user-edit-own-link]' : $target;
  }
}