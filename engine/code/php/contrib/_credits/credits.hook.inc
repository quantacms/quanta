<?php
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 05/06/15
 * Time: 11:49
 */
function credits_user_load($vars) {
  $credits = $vars['user']->getData('credits');
  if (!$credits) {
      $vars['user']->setData("credits", 10000);
    }
}

function credits_user_save($vars) {
  $credits = $vars['user']->getData('credits');
  if (!$credits) {
    $vars['user']->setData('credits', 0);
  }
}
