<?php
namespace Quanta\Common;


function import_shadow_import_file_form($env, $vars) {
  $vars['shadow']->addTab('Import file', file_get_contents($env->getModulePath('import') . '/tpl/import_file_form.inc'), 2);
}

/**
 * Implements hook_node_save().
 *
 * Saves all files uploaded on the node.
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function import_node_after_save(Environment $env, array &$vars) {
    \Quanta\Common\ImportFactory::importData($env,$vars);   
}
