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
    try {
        $node = $vars['node'];
        $file = array_pop($vars['uploaded_files']);
        $fileobj = new FileObject($env, $file, $node);
        if($fileobj && $fileobj->exists){
            
            $filename = $fileobj->getNode()->getPath() . '/' . $fileobj->getPath();
    
            // Open the CSV file
            if (!file_exists($filename) || !is_readable($filename)) {
                return false;
            }
          
            if (($handle = fopen($filename, 'r')) !== false) {
                $counter = 0;
                $imported_data = [];
                // Loop through each line of the CSV file
                while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                    $counter++;
                    if($counter === 1) {    continue;}
                    $row_data = [];
                    // print_r($row);
                    for ($i=0; $i <count($row); $i++) { 
                        $item = $row[$i];
                       $row_data[$i] = $item ;
                    }
                    $imported_data []=   $row_data;
                }
                // Close the file
                fclose($handle);
                $vars['imported_data'] = $imported_data;
                $env->hook('file_imported',$vars);
            }
        }
      
    } catch (\Throwable $th) {
       print_r($th);
       die();
    }
  

}