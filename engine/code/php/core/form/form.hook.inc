<?php
/**
 * Implements hook_load_includes().
 *
 * @param $vars
 *   Miscellaneous environment / page variables.
 */
function form_load_includes($vars) {
  /** @var Environment $env */
  $env = $vars['env'];
  $env->addInclude('engine/code/php/core/form/css/form.css');
  $env->addInclude('engine/code/php/core/form/js/form.js');
  $env->addInclude('engine/code/php/core/form/addons/autocomplete/easy-autocomplete.min.css');
  $env->addInclude('engine/code/php/core/form/addons/autocomplete/easy-autocomplete.themes.min.css');
  $env->addInclude('engine/code/php/core/form/addons/autocomplete/jquery.easy-autocomplete.min.js');
}

/**
 * Check out that required fields are met, and other stuff.
 * Implements hook_form_validate();
 * @param $vars
 */
function form_form_validate($vars) {
  /** @var Form $form */
  $form = &$vars['form'];

  foreach ($form->getItems() as $k => $item) {
    // Check if the item is required.
    /** @var FormItem $item */
    if ($item->isRequired() && (empty($_REQUEST[$item->getName()]) || trim($_REQUEST[$item->getName()]) == '')) {
      $form->validationError($item, 'This item is required!');
    }
    $item->validate();
  }
}

/**
 * Implements hook_boot.
 * TODO: refactor the whole.
 *
 * @param $vars
 */
function form_boot($vars) {
  /** @var Environment $env */
  $env = $vars['env'];
  // TODO: object oriented, using templates, etc. etc.
  if ($env->getRequestedPath() == 'autocomplete') {
    $autocomplete_results = search($env, $_GET['search_string'], NodeFactory::load($env, $_GET['search_node']), array('title'), SEARCH_OUTPUT_MATCH);

    header("Content-type: application/json");
    $results = array();
    foreach ($autocomplete_results as $autocomplete_result) {
$node = NodeFactory::loadFromRealPath($vars['env'], explode(':', $autocomplete_result)[0]);
      // TODO: check user access.
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

