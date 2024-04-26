<?php
namespace Quanta\Common;

/**
 * Implements hook_complete().
 * Run the Garbage collector.
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function environment_complete(Environment $env, array $vars){
  // TODO: include the Garbage collector.
}

/**
 * Implements hook_doctor().
 *
 * Delete the cached class map and perform other environment-related operations.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function environment_doctor(Environment $env, $vars) {
  /** @var Doctor $doctor */
  $doctor = $vars['doctor'];
  $doctor->op('Deleting cached Class Map file...');
  unlink(CLASS_MAP_FILE);
  $doctor->ok('Done!');
}

function environment_boot(Environment $env, $vars){
  $full_host = strtolower($_SERVER['HTTP_HOST']);
  $host = explode(':', $full_host)[0];
  $envFilePath= $_SERVER['DOCUMENT_ROOT']. '/sites/'.$host.'/.env';
  // Check if the .env file exists
    if (file_exists($envFilePath)) {
        // get env file contents
        $fileContent = file_get_contents($envFilePath);
        // Split the content into lines
        $lines = explode("\n", $fileContent);
       

        // Iterate through each line
        foreach ($lines as $line) {
          // Ignore empty lines and lines starting with #
          if (!empty($line) && strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            // Split the line into key and value
            list($key, $value) = explode('=', $line, 2);  
            $env->setData(trim($key), trim($value));
          }
         }
    
    } 
}
