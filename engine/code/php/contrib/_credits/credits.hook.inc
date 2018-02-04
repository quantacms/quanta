<?php
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 05/06/15
 * Time: 11:49
 * @param $vars
 */
function credits_user_load($env, $vars) {
  /** @var User $user */
  $user = $vars['user'];
  $credits = $user->getData('credits');
  if (!$credits) {
    $user->setData("credits", 10000);
  }
}

/**
 * @param $env
 * @param $vars
 */
function credits_user_save($env, $vars) {
  $credits = $vars['user']->getData('credits');
  if (!$credits) {
    $vars['user']->setData('credits', 0);
  }
}
