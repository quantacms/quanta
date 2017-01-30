<?php

/**
 * Implements hook_init().
 * @param mixed $vars
 */
function form_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/form/css/form.css');
}

/**
 * Check out that required fields are met, and other stuff.
 * Implements hook_form_validate();
 * @param $vars
 */
function form_form_validate($vars) {
  $form = FormFactory::getForm($vars['env'], $vars['form']->getId());
  foreach ($form->getItems() as $k => $item) {
    if ($item->isRequired() && (empty($_REQUEST[$item->getName()]) || trim($_REQUEST[$item->getName()]) == '')) {
      $form->validationError($item, 'This item is required!');
    }
    $item->validate();
  }
}

