<?php
namespace Quanta\Common;

/**
 * Implements hook_page_complete()
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function qtags_page_complete($env, $vars) {
  $html = QtagFactory::transformCodeTags($env, $vars['page']->html);
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
function qtags_boot($env, $vars) {
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
function qtags_load_includes($env, $vars) {
  $module_path = $env->getModulePath('qtags');
  $env->addInclude($module_path . '/css/qtags.css');
}
