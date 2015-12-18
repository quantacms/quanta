<?php

/**
 * Implements hook_init().
 * @param mixed $vars
 */
function form_page_init($vars) {
  if (isset($_REQUEST['form_submit'])) {
    $form = array('form' => $vars['page']->getData('form_' . $_REQUEST['form'], NULL));
    $vars['env']->hook('form_validate', $form);
  }
}

/**
 * Check out that required fields are met, and other stuff.
 * Implements hook_form_validate();
 * @param $vars
 */
function form_form_validate($vars) {
  $form = $vars['form'];
  $errors = array();
  foreach ($form as $form_item) {
    if (isset($form_item['required']) && empty($_REQUEST[$form_item['name']])) {
      $errors[] = ("Element " . $form_item['name'] . ' is required!');
    }
  }
  if (count($errors) == 0) {
    $vars['env']->hook('form_submit');
    $vars['env']->hook($form['form'] . '_form_submit');

  }
  else {
    foreach ($errors as $error) {
      print_r("ERROR");
      new Message($vars['env'], $error, MESSAGE_ERROR, MESSAGE_TYPE_SCREEN);
    }
  }
}