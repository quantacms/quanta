<?php
// Include the Environment module.
  require_once('modules/core/environment/environment.module');

  // Include the Cache module.
  require_once('modules/core/cache/cache.module');

  // Pre-set host.
  if (!isset($host)) {
    $host = NULL;
  }
  // Pre-set request uri.
  if (!isset($request_uri)) {
    $request_uri = NULL;
  }

  // Pre-set document root.
  if (!isset($docroot)) {
    $docroot = NULL;
  }


  // Create a new Environment.
  $env = new Environment($host, $request_uri, $docroot);

  // Check if the current request is a file rendering request.
  $env->checkFile();

  // Load the environment.
  $env->load();

  // Start the user session.
  $env->startSession();
  // Run all system modules.
  $env->runModules();
  // Run the boot hook.
  $env->hook('boot');

  // Start page's standard index.html.
  $page = new Page($env);
  $vars = array('page' => &$page);

  $env->setData('page', $vars['page']);

  // Run the init hook.

  if (!isset($_REQUEST['ajax'])) {
    $env->hook('load_includes', $vars);
    $page->loadIncludes();
  }

  // Initialize doctor, if there is a request to do so.
  if (isset($doctor_cmd)) {
    $doctor = new Doctor($env, $doctor_cmd);
    $doctor->cure();
    $doctor->goHome();
    exit;
  }

  // Check if there is any requested action.
  $env->checkActions();

  // Run the init hook.
  $env->hook('init', $vars);

  // Build the page's HTML code.
  $page->buildHTML();

  // Render the page.
  print $page->render();

  // Run the complete hook.
  $env->hook('complete');

  // End the bootstrap.
  exit();
