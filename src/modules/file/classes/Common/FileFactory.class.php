<?php
namespace Quanta\Common;
/**
 * Class FileFactory
 *
 * This Factory class contains static methods for loading, manipulating, saving
 * and deleting Nodes. Also serves as a rendering tool for loading and
 * applying Node Templates.
 *
 */
class FileFactory {
  /**
   * Check if the current request is for a file.
   */
  public static function checkFile(Environment $env) {
    // Support for letsencript https certificates.
    if (!empty($env->request_path) && $env->request_path == 'acme-challenge') {
      readfile('/' . trim($env->dir['docroot'] . implode('/', $env->request), '/'));
      die();
    }
    // TODO: redo the whole shit.
    if (strpos($env->request[count($env->request) - 1], '.') > 0) {
      $filename = $env->request[count($env->request) - 1];

      $dir = $env->request[count($env->request) - 2];
      $nodepath = Cache::getStoredNodePath($env, $dir);
      $file = $nodepath . '/' . urldecode($filename);
      if (is_file($file)) {
        // Request for a file download.
        if (isset($_GET['download'])) {
          header('Pragma: public');
          header('Expires: 0');
          header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
          header('Content-Type: ' . mime_content_type($file));
          header('Cache-Control: private', false); // required for certain browsers
          header('Content-Disposition: attachment; filename="'. basename($file) . '";');
          header('Content-Transfer-Encoding: binary');
          header('Content-Length: ' . filesize($file));
        }
        else {
          header('Content-Type: ' . mime_content_type($file));
        }
        // TODO: support for xsendfile.
        $mods = array_flip(apache_get_modules());
        if (isset($mods['mod_xsendfile'])) {
          // readfile($file);
        }
        else {
          //TODO : slow, insecure...
          // Render a file.
          readfile($file);
        }
        exit();
      }
      elseif ($filename == 'favicon.ico') {
        die("No favicon available.");
      }
    }
  }

  /**
   * Check if the current request is for download pdf.
   */
  public static function downloadPdf(Environment $env){
    if (!empty($env->request_path) && $env->request_path == 'download-pdf') {
      // Extract query parameters
      $file_path = $env->query_params['file-name'];
      $target = $env->query_params['target'];
      // Load the target node
      $node = \Quanta\Common\NodeFactory::load($env, $target);
      if($node->exists){
        // Create a file object and download the file
        $file = new  \Quanta\Common\FileObject($env,$file_path,$node);
        if($file->exists){
          $file->download();
        }
      }
      
    } 
  }

  public static function importFileData(Environment $env,$vars){
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
