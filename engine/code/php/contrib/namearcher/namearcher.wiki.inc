<?php
function wiki_LEVEL($replace, $attributes, $usr, $string, $page) {
  if ($usr == NULL) {
    $user = User::current($page->env);
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
    $user = User::current($page->env);
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

function wiki_PERCENERGY($replace, $attributes, $usr, $string, $page) {
  $perc = 75;
  return str_replace($replace, $perc, $string);
}

function wiki_PERCTONEXTLEVEL($replace, $attributes, $usr, $string, $page) {
  if ($usr == NULL) {
    $user = User::current($page->env);
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
  return intval(log($exp / 100, 2)) + 1;
}

function expToLevel($exp, $level) {
  $need = pow(2, $level) * 100;
  return ($need);
}

function percToLevel($exp, $level) {

  $need = pow(2, $level) * 100;
  return ($need - $exp);
}