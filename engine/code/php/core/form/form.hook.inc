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
    $autocomplete_results = search($vars['env'], $_GET['search_string'], NodeFactory::load($vars['env'], $_GET['search_node']), array('title'), SEARCH_OUTPUT_MATCH);

    header("Content-type: application/json");
    $user = UserFactory::current($vars['env']);
    $results = array();
    foreach ($autocomplete_results as $autocomplete_result) {
$node = NodeFactory::loadFromRealPath($vars['env'], explode(':', $autocomplete_result)[0]);
      // TODO: check user access with || $user->checkAccess($node).
      if (TRUE) {
        $results[] = array(
          'name' => $node->getName(),
          'title' => $node->getTitle(),
        );
      }
    }

    print json_encode($results);
    die();
  }
}

