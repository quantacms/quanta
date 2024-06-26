<?php
namespace Quanta\Common;
/**
 * Class ImportFactory
 *
 *
 */
class ImportFactory {

  public static function importData(Environment $env,$vars){
    if(isset($vars['uploaded_files'])){
        $node = $vars['node'];
        $file = array_pop($vars['uploaded_files']);
        $fileobj = new \Quanta\Common\FileObject($env, $file, $node);
        $imported_data = [];
        if($fileobj && $fileobj->exists && $fileobj->extension == 'csv'){
            $filename = $fileobj->getNode()->getPath() . '/' . $fileobj->getPath();
            // Open the CSV file
            if (!file_exists($filename) || !is_readable($filename)) {
                return false;
            }
            if (($handle = fopen($filename, 'r')) !== false) {
                $counter = 0;
                
                // Loop through each line of the CSV file
                while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                    $counter++;
                    if($counter === 1) {    continue;}
                    $row_data = [];
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
                unlink($filename);
            }
        }
        return $imported_data;
    }
  
  }


}
