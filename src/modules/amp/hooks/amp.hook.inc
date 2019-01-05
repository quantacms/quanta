<?php
namespace Quanta\Common;

/**
 * Implements hook_init().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function amp_page_preload(Environment $env, array $vars) {
  if (\Quanta\Common\Amp::isActive($env)) {
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
function amp_load_includes(Environment $env, array $vars) {
  $module_path = $env->getModulePath('amp');

  $env->addInclude($module_path . '/assets/css/amp.css');
}

/**
 * Implements hook_page_complete()
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function amp_page_render(Environment $env, array $vars) {
  if (Amp::isActive($env)) {
    $vars['page']->html = preg_replace('/style=\\"[^\\"]*\\"/', '', $vars['page']->html);
    $vars['page']->html = str_replace('<img', '<amp-img layout="intrinsic"', $vars['page']->html);
    $vars['page']->html = str_replace('<form', '<amp-form layout="intrinsic"', $vars['page']->html);
    $vars['page']->html = str_replace('<style>', '<style amp-custom>', $vars['page']->html);
    $vars['page']->html = str_replace('!important', '', $vars['page']->html);
    // TODO: where does this target pmc_ext come from?
		$vars['page']->html = str_replace('pmc_ext', '_blank', $vars['page']->html);
  }
}

/**
 * Implements hook_qtag_preload().
 *
 * Filter items by amp attributes.
 *
 * @see Box.class.inc
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function amp_qtag_preload(Environment $env, array $vars) {
  if (Amp::isActive($env) && !empty($vars['attributes']['no_amp'])) {
    // AMP is active: remove all no_amp items.
    $vars['access'] = FALSE;
  } 
  elseif (!Amp::isActive($env) && !empty($vars['attributes']['amp_only'])) {
    // AMP is not active: remove all amp_only items.
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
function amp_link_alter(Environment $env, array $vars) {
  if (Amp::isActive($env) && empty($vars['attributes']['external'])) {
    $vars['qtag']->destination = '/amp' . $vars['qtag']->destination;
  }
}
