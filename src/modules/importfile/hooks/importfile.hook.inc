<?php
namespace Quanta\Common;


function importfile_shadow_import_file_form($env, $vars) {
    $vars['shadow']->addTab('Import file', file_get_contents($env->getModulePath('importfile') . '/tpl/import_file_form.inc'), 2);
}

/**
 * Implements hook_node_save().
 *
 * 
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function importfile_node_after_save(Environment $env, array $vars) {
    \Quanta\Common\ImportFileFactory::importFileData($env,$vars);   
}