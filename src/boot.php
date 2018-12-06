<?php
  // Boot Quanta.

  // The Environment class.
  // Of the class map / autoloader.
  require_once('modules/environment/classes/Common/DataContainer.class.php');
  require_once('modules/environment/classes/Common/Environment.class.php');

  // Create a new Environment.
  $env = new \Quanta\Common\Environment(
    empty($host) ? NULL : $host,
    empty($request_uri) ? NULL : $request_uri,
    empty($docroot) ? NULL : $docroot);

  // Include the class autoloader.
  require_once('autoload.php');

  // Load the environment.
  $env->load();

  // If classes are not mapped (i.e. in a fresh install), we need to manually include Environment, so it can
  // produce the class map using its API functions.
  if (!file_exists(CLASS_MAP_FILE)) {
    $env->mapClasses();
  }

  // Check if the current request is a file rendering request.
  \Quanta\Common\FileFactory::checkFile($env);

  // Start the user session.
  $env->startSession();

  // Run the boot hook.
  $env->hook('boot');

  // Check if there is an AJAX request in progress. TODO: move in ajax module.
  if (!isset($_REQUEST['ajax'])) {
    $env->hook('load_includes', $vars);
  }

  // Initialize doctor, if there is a request to do so.
  if (isset($doctor_cmd)) {
    $doctor = new \Quanta\Common\Doctor($env, $doctor_cmd, $doctor_args);
    $doctor->cure();
    $doctor->goHome();
    exit;
  }

  // Check if there is any requested action.
  $env->checkActions();

  // Run the init hook.
  $env->hook('init', $vars);

  // Run the complete hook.
  $env->hook('complete');

  // Complete the boot process.
  exit();