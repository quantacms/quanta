<?php
namespace Quanta\Common;




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
function qtag_USER_EDIT_OWN($env, $target, &$attributes) {
  $user = ($target == NULL) ? UserFactory::current($env) : new User($env, $target);
  if (UserAccess::check($env, USER_ACTION_EDIT, array('user' => $user))) {
    $title = isset($attributes['title']) ? $attributes['title'] : 'Edit your profile';
    return $user->exists ? '[LINK|title=' . $title . '|link_class=user-edit-own-link]' : $target;
  }
  else {
    return '';
  }
}