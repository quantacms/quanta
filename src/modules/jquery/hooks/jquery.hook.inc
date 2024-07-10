<?php
namespace Quanta\Common;
function jquery_page_init(Environment $env, &$vars) {
	// TODO: not a decent criteria for deciding when to include jquery widgets.
		/** @var Page $page */
		$page = $vars['page'];
		$page->addJS('/modules/jquery/assets/js/jquery-ui.min.js');
		$page->addJS('/modules/jquery/assets/js/jquery.ui.widget.js');
		$page->addJS('/modules/jquery/assets/js/jquery.tablesorter.js');
	
}
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
