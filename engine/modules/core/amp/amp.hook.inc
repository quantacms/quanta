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
  if (Amp::isActive($env)) {
    $page = $env->getData('page');
    $page->setIndexFile('index-amp.html');
  }
}


/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function amp_load_includes($env, $vars) {
  $module_path = $env->getModulePath('amp');

  $env->addInclude($module_path . '/css/amp.css');
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
  if (Amp::isActive($env)) {

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
  if (Amp::isActive($env)) {
    $vars['page']->html = preg_replace('/style=\\"[^\\"]*\\"/', '', $vars['page']->html);
    $vars['page']->html = str_replace('<img', '<amp-img layout="intrinsic"', $vars['page']->html);
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
  if (Amp::isActive($env) && !empty($vars['attributes']['no_amp'])) {
    $vars['access'] = FALSE;
  }
}

/**
 * Implements hook_link_alter().
 *
 * Automatically amp-ize link in amp pages.
 *
 * @see Box.class.inc
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function amp_link_alter($env, $vars) {
  if (Amp::isActive($env) && $vars['attributes']['external'] == FALSE) {
    $vars['target'] = '/amp' . $vars['target'];
  }
}
