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
function form_load_includes(Environment $env, array $vars) {
  $module_path = $env->getModulePath('form');
  $env->addInclude($module_path . '/assets/css/form.css');
  $env->addInclude($module_path . '/assets/js/form.js');
  $env->addInclude($module_path . '/addons/autocomplete/jquery.easy-autocomplete.min.js');
}

/**
 * Implements hook_boot().
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function form_boot(Environment $env, array $vars) {
  $url= $_SERVER['REQUEST_URI'];
  // Parse the URL
  $urlComponents = parse_url($url);

  // Extract and parse the query string parameters
  parse_str($urlComponents['query'], $params);
  if(isset($params['async_submission'])){
   
    $form_state = \Quanta\Common\FormFactory::getFormState($env, $_REQUEST['form']);
    $check= $form_state->checkValidate(true);
    $validation_errors= $form_state->getData('validation_errors') ? $form_state->getData('validation_errors') : [];
    print json_encode($validation_errors);
    exit();
  }

  // Check if there is a submitted form, and generated its state.
  if (isset($_REQUEST['form'])) {
    $form_state = \Quanta\Common\FormFactory::getFormState($env, $_REQUEST['form']);
    $form_state->checkValidate();
  }
  // Autocomplete.
  // TODO: object oriented, using templates, etc. etc.
  if ($env->getRequestedPath() == 'autocomplete') {
    print _form_json_autocomplete_results($env, $vars);
    exit();
  }

}

/**
 * TODO: OO refactoring.
 *
 * Fetch autocomplete results for a form autocomplete field.
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 * @param $env
 * @param $vars
 */
function _form_json_autocomplete_results($env, $vars) {
  header("Content-type: application/json");
  $items = new \Quanta\Common\DirList($env, $_REQUEST['search_node'], NULL);
  $results = array();
  $autocomplete_results = $items->getItems();
  foreach ($autocomplete_results as $node) {

    // TODO: check user access.
    if (strpos(strtolower($node->getTitle()), strtolower($_REQUEST['search_string'])) !== FALSE) {
      $results[] = array(
        'name' => $node->getName(),
        'title' => $node->getTitle(),
      );
    }
  }
  return json_encode($results);
}