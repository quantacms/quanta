<?php
function wiki_LEVEL($attributes, $usr, $string, $env) {
  if ($usr == NULL) {
    $user = UserFactory::current($env,  TRUE);
  } else {
    $user = new User($env, $usr);
  }
  if (!$user->exists) {
    return $string;
  }
  $exp = Points::get($env, $user, 'experience');
  $level = NameArcher::getLevel($exp);
  return $level;
}

function wiki_TONEXTLEVEL($attributes, $usr, $string, $env) {
  if ($usr == NULL) {
    $user = UserFactory::current($env, TRUE);
  } else {
    $user = new User($env, $usr);
  }
  if (!$user->exists) {
    return $string;
  }

  $exp = Points::get($env, $user, 'experience');
  $level = NameArcher::getLevel($exp);
  $tonext = NameArcher::expToLevel($level+1);
  return $tonext;
}

function wiki_PERCTONEXTLEVEL($attributes, $usr, $string, $env) {
  if ($usr == NULL) {
    $user = UserFactory::current($env, TRUE);
  } else {
    $user = new User($env, $usr);
  }
  if (!$user->exists) {
    return $string;
  }
  $exp = Points::get($env, $user, 'experience');
  $level = NameArcher::getLevel($exp);
  $perc = NameArcher::percToLevel($exp, $level + 1);
  return $perc;
}


