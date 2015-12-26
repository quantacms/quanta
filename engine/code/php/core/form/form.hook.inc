<?php

/**
 * Implements hook_init().
 * @param mixed $vars
 */
function form_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/form/css/form.css');
}

/**
 * Implements hook_after_build().
 * @param mixed $vars
 */
function form_form_load($page, $form_name) {
    $form = array('form' => $page->getData('form_' . $form_name, NULL));
    $page->env->hook('form_validate', $form);
  return !isset($_SESSION['validation_errors'][$form_name]);
}

/**
 * Check out that required fields are met, and other stuff.
 * Implements hook_form_validate();
 * @param $vars
 */
function form_form_validate($vars) {
  $errors = array();
  $form = $vars['form'];
  foreach ($form as $k => $form_item) {
    if (isset($form_item['required']) && (empty($_REQUEST[$form_item['name']]) || trim($_REQUEST[$form_item['name']]) == '')) {
      $_SESSION['validation_errors'][$_REQUEST['form']][$form_item['name']] = TRUE;
      $errors[] = ("Element " . $form_item['name'] . ' is required!');
    }
  }

  if (count($errors) == 0) {
    $vars['env']->hook('form_submit', $form);
    $vars['env']->hook($_REQUEST['form'] . '_form_submit');
  }
  else {
    foreach ($errors as $error) {
      new Message($vars['env'], $error, MESSAGE_ERROR, MESSAGE_TYPE_SCREEN);
    }
  }
}

/**
 * Implements hook_after_build().
 * @param mixed $vars
 */
function form_complete($vars) {
  unset($_SESSION['validation_errors']);
}
