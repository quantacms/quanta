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
 * Rebuild the cached class map and perform other environment-related operations.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function environment_doctor(Environment $env, $vars) {
  environment_doctor_class_map($env, $vars);
}

/**
 * Implements hook_doctor_class_map().
 *
 * Rebuild the cached class map from the code this process runs.
 *
 * It is rebuilt rather than deleted: a request builds a missing map from ITS
 * code, and where static/ is shared by processes running different code (the
 * containers of a site during a rolling update) that may be one still running
 * the previous release, whose map the others would then keep.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function environment_doctor_class_map(Environment $env, $vars) {
  /** @var Doctor $doctor */
  $doctor = $vars['doctor'];
  $doctor->op('Rebuilding cached Class Map file...');
  $env->mapClasses();
  $doctor->ok('Done!');
}

function environment_boot(Environment $env, $vars){
  $envFilePath=  $env->dir['docroot'].'/.env';
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
