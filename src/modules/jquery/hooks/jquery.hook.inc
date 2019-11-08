<?php
namespace Quanta\Common;

/**
 * Implements hook_boot().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function jquery_boot(Environment $env, array $vars) {
  $module_path = $env->getModulePath('jquery');
  $env->addInclude($module_path . '/assets/js/jquery.min.js');
  $env->addInclude($module_path . '/assets/js/engine.js');

}

/**
 * Implements hook_page_init();
 * @param $vars
 */
function jquery_page_init(Environment $env, &$vars) {
  $user = UserFactory::current($env);
  // TODO: not a decent criteria for deciding when to include jquery widgets.
  if ($user->hasRole('logged')) {
    /** @var Page $page */
    $page = $vars['page'];
    $page->addJS('/modules/jquery/assets/js/jquery-ui.min.js');
    $page->addJS('/modules/jquery/assets/js/jquery.ui.widget.js');
    $page->addJS('/modules/jquery/assets/js/jquery.tablesorter.js');
  }
}