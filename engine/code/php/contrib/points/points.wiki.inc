<?php
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 25/06/15
 * Time: 12:50
 */

function wiki_POINTS($replace, $attributes, $usr, $string, $page) {
  if ($usr == NULL) {
    $user = User::current($page->env);
  } else {
    $user = new User($page->env, $usr);
  }

    $show = isset($attributes['show']) ? $attributes['show'] : 'current';
    return str_replace($replace, Points::get($page->env, $user, $attributes['type'], $show), $string);

 }
