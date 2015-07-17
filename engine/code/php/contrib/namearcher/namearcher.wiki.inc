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
  $tonext = expToLevel($level+1);
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
  $perc = percToLevel($exp, $level + 1);
  return str_replace($replace, $perc, $string);
}

function getLevel($exp) {
  if ($exp <= 100) {
    return 1;
  }
  $level = intval(log(intval($exp / 100), 2)) + 1;
  return $level;
}

function expToLevel($level) {
  if ($level < 2) {
    return 0;
  }
  $need = pow(2, $level - 1) * 100;
  return ($need);
}

function percToLevel($exp, $level) {
  $prev = expToLevel($level - 1);
  $need = expToLevel($level);
  return intval(($exp - $prev) / ($need - $prev) * 100);
}

