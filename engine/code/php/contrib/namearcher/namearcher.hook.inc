<?php
/**
 * Set credits to zero if points variable has not been registered.
 * @param $vars
 */
function namearcher_user_save($vars) {
  $points = $vars['user']->getData('points');
  if (!$points) {
    $vars['user']->setData('points', array('energy' => array('current' => 1000, 'max' => 1000)));
    $vars['user']->setData('experience', array('energy' => array('current' => 0, 'max' => 1000000000)));
  }
}


function namearcher_shadow_namearcher_message($vars) {

  $text = file_get_contents('contrib/namearcher/tpl/' . $vars['shadow']->getData('message') . '.inc');
  $vars['shadow']->addTab('Info', $text, 1);
}

