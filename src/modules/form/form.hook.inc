<?php
namespace Quanta\Common;

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function form_load_includes($env, $vars) {
  $module_path = $env->getModulePath('form');
  $env->addInclude($module_path . '/css/form.css');
  $env->addInclude($module_path . '/js/form.js');
  $env->addInclude($module_path . '/addons/autocomplete/easy-autocomplete.min.css');
  $env->addInclude($module_path . '/addons/autocomplete/easy-autocomplete.themes.min.css');
  $env->addInclude($module_path . '/addons/autocomplete/jquery.easy-autocomplete.min.js');
}

/**
 * Implements hook_form_validate();
 * Check that required fields are met, and other standard validations.
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function form_form_validate($env, $vars) {
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
 * Implements hook_boot().
 * TODO: This approach was made for fetching results for autocomplete fields. Refactor the whole.
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function form_boot($env, $vars) {
  // TODO: object oriented, using templates, etc. etc.
  if ($env->getRequestedPath() == 'autocomplete') {
    $autocomplete_results = search($env, $_GET['search_string'], NodeFactory::load($env, $_GET['search_node']), array('title'), SEARCH_OUTPUT_MATCH);
    header("Content-type: application/json");
    $results = array();
    foreach ($autocomplete_results as $autocomplete_result) {
$node = NodeFactory::loadFromRealPath($env, explode(':', $autocomplete_result)[0]);
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
