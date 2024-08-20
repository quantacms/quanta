<?php
namespace Quanta\Common;

/**
 * Implements hook_qtag_preload()
 * Allows for setting up dynamically attributes to a qtag
 * by loading their key values pair from the json of a given
 * node.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function qtags_qtag_preload(Environment $env, array $vars) {
  if (isset($vars['qtag']->attributes['attributes'])) {
    // Try loading the node that will contain the attributes to embed in the qtag.
    $attributes_node = \Quanta\Common\NodeFactory::load($env, $vars['qtag']->attributes['attributes']);
    if ($attributes_node->exists) {
      // Assign the attributes to the Qtag.
      foreach ($attributes_node->json as $key => $value) {
        $vars['qtag']->attributes[$key] = $value;
      }

    }
  }
}

/**
 * Implements hook_page_complete()
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function qtags_page_complete(Environment $env, array $vars) {
  $html = str_replace("\n", "", $vars['page']->html);
  $html = QtagFactory::transformCodeTags($env, $html);
  $html = QtagFactory::transformCodeTags($env, $html, array('runlast' => TRUE));
  $vars['page']->html = $html;
}

/**
 * Implements hook_boot().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param $vars
 *   An array of variables.
 */
function qtags_boot(Environment $env, array $vars) {
  if ($env->request[1] == 'q') {
    $env->setContext('qtag');
		print QtagFactory::transformCodeTags($env, '[' . $env->request[2] . '|' . urldecode($env->request[3]) . ']');
    exit;
  }
  if ($env->request[1] == 'qtag') {
    $env->setContext('qtag');
		print QtagFactory::transformCodeTags($env, urldecode($env->request[2]));
    exit;
  }
  qtags_check_API($env, $vars);
}

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function qtags_load_includes(Environment $env, array $vars) {
  $module_path = $env->getModulePath('qtags');
  $env->addInclude($module_path . '/assets/css/qtags.css');
}

    /**
   * Check if there are any API request.
   */
  function qtags_check_API($env, $vars) {
    if (strpos($env->request_uri, '/api/') !== false) {
      $response = new \stdClass();
      $apiPosition = strpos($env->request_uri, '/api/');
      $path = substr($env->request_uri, $apiPosition + strlen('/api/'));
      $path = rtrim($path, '/');
      $error_code = null;
      $error_message = null;
          // Check the request method
          switch ($_SERVER['REQUEST_METHOD']) {
            case 'GET':
                $vars['api'] = $path;
                $vars['data'] = (array) $env->query_params;
                $env->hook('api_get_' . $path, $vars);
                break;

            case 'POST':
              $vars['api'] = $path;
              $vars['data'] = (array) $_POST;
              $env->hook('api_post_' . $path, $vars);
                break;

            default:
                $error_code = 'unsupported_request_method';
                $error_message = 'Unsupported request method.';
                break;
 
    }
    // If no function returns a response, this means that this function does not exist
    $response->success = false;
    $response->error_code = $error_code ? $error_code : 'api_not_found';
    $response->error_message = $error_message ? $error_message : 'This API not found.';
    $response_json = json_encode($response);
    http_response_code(404);
    exit($response_json);  
  } 
}
