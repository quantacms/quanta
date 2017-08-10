<?php
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 25/06/15
 * Time: 12:50
 */

function wiki_POINTS($attributes, $usr, $string, $env) {
  if ($usr == NULL) {
    $user = UserFactory::current($env, TRUE);
  } else {
    $user = new User($env, $usr);
  }

    $show = isset($attributes['show']) ? $attributes['show'] : 'current';
    return Points::get($env, $user, $attributes['type'], $show);

 }
