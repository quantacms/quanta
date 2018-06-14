<?php

/**
 * Implements hook_init().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function amp_init($env, $vars) {
  if ($env->request[1] == 'amp') {
    $page = $env->getData('page');
    $page->setIndexFile('index-amp.html');
  }
}


/**
 * Implements hook_html_alter().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function amp_qtag($env, $vars) {
  if ($env->request[1] == 'amp') {

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
function amp_page_complete($env, $vars) {
  if ($env->request[1] == 'amp') {
    $vars['page']->html = preg_replace('/style=\\"[^\\"]*\\"/', '', $vars['page']->html);
    $vars['page']->html = str_replace('<img', '<amp-img', $vars['page']->html);
    $vars['page']->html = str_replace('<style>', '<style amp-custom>', $vars['page']->html);
    $vars['page']->html = str_replace('!important', '', $vars['page']->html);
  }
}

/**
 * Implements hook_qtag().
 *
 * Remove all no_amp items.
 *
 * @see Box.class.inc
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function amp_qtag_preload($env, $vars) {
  if ($env->request[1] == 'amp' && !empty($vars['attributes']['no_amp'])) {
    $vars['access'] = FALSE;
  }
}
