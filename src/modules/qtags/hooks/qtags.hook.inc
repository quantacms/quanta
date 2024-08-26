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
