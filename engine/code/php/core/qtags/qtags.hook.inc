<?php
/**
 * Implements hook_page_complete()
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function qtags_page_complete($env, $vars) {
  $html = transformCodeTags($env, $vars['page']->html);
  $html = transformCodeTags($env, $html, array('runlast' => TRUE));

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
    print transformCodeTags($env, urldecode($env->request[2]));
    exit;
  }
}