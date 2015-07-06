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
  $level = getLevel($exp);
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
  $level = getLevel($exp);
  $tonext = expToLevel($exp, $level+1);
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
  $level = getLevel($exp);
  $tonext = expToLevel($exp, $level+1);

  $perc = 100 - (intval($exp / $tonext * 100) + 1);
  return str_replace($replace, $perc, $string);
}

function getLevel($exp) {
  $level = intval(log($exp / 1000 + 1, 2)) + 1;
  print "LEVEL: " . $level;
  return $level;
}

function expToLevel($exp, $level) {
  $need = pow(2, $level) * 100 + 1;
  return ($need);
}

function percToLevel($exp, $level) {

  $need = pow(2, $level) * 100;
  return ($need - $exp);
}