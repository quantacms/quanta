<?php
function wiki_LEVEL($replace, $attributes, $usr, $string, $page) {
  if ($usr == NULL) {
    $user = User::current($page->env,  TRUE);
  } else {
    $user = new User($page->env, $usr);
  }
  if (!$user->exists) {
    return $string;
  }
  $exp = Points::get($page->env, $user, 'experience');
  $level = NameArcher::getLevel($exp);
  return str_replace($replace, $level, $string);
}

function wiki_TONEXTLEVEL($replace, $attributes, $usr, $string, $page) {
  if ($usr == NULL) {
    $user = User::current($page->env, TRUE);
  } else {
    $user = new User($page->env, $usr);
  }
  if (!$user->exists) {
    return $string;
  }

  $exp = Points::get($page->env, $user, 'experience');
  $level = NameArcher::getLevel($exp);
  $tonext = NameArcher::expToLevel($level+1);
  return str_replace($replace, $tonext, $string);
}

function wiki_PERCTONEXTLEVEL($replace, $attributes, $usr, $string, $page) {
  if ($usr == NULL) {
    $user = User::current($page->env, TRUE);
  } else {
    $user = new User($page->env, $usr);
  }
  if (!$user->exists) {
    return $string;
  }
  $exp = Points::get($page->env, $user, 'experience');
  $level = NameArcher::getLevel($exp);
  $perc = NameArcher::percToLevel($exp, $level + 1);
  return str_replace($replace, $perc, $string);
}


