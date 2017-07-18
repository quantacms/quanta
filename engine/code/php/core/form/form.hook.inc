<?php

/**
 * Implements hook_init().
 * @param mixed $vars
 */
function form_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/form/css/form.css');
  $vars['env']->addInclude('engine/code/php/core/form/js/form.js');
  $vars['env']->addInclude('engine/code/php/core/form/addons/autocomplete/easy-autocomplete.min.css');
  $vars['env']->addInclude('engine/code/php/core/form/addons/autocomplete/easy-autocomplete.themes.min.css');
  $vars['env']->addInclude('engine/code/php/core/form/addons/autocomplete/jquery.easy-autocomplete.min.js');
}

/**
 * Check out that required fields are met, and other stuff.
 * Implements hook_form_validate();
 * @param $vars
 */
function form_form_validate($vars) {
  $form = &$vars['form'];

  foreach ($form->getItems() as $k => $item) {
    // Check if the item is required.
    if ($item->isRequired() && (empty($_REQUEST[$item->getName()]) || trim($_REQUEST[$item->getName()]) == '')) {
      $form->validationError($item, 'This item is required!');
    }
    $item->validate();
  }
}


function form_boot($vars) {
  // TODO: object oriented, using templates, etc. etc.
  if ($vars['env']->getRequestedPath() == 'autocomplete') {
    header("Content-type: application/json");
    $results = array('test', 'aldo', 'alien', 'tette', 'ararara', 'seguito');
    print json_encode($results);
    die();
  }
}

