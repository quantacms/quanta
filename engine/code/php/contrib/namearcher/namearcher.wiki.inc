<?php
function wiki_LEVEL($attributes, $usr, $string, $page) {
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
  return $level;
}

function wiki_TONEXTLEVEL($attributes, $usr, $string, $page) {
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
  return $tonext;
}

function wiki_PERCTONEXTLEVEL($attributes, $usr, $string, $page) {
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
  return $perc;
}


