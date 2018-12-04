<?php
  require_once('autoload.php');

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
  $env = new \Quanta\Common\Environment($host, $request_uri, $docroot);

  // Load the environment.
  $env->load();

  // Run all system modules.
  $env->runModules();

  // Check if the current request is a file rendering request.
  $env->checkFile();

  // Start the user session.
  $env->startSession();

  // Run the boot hook.
  $env->hook('boot');

  // Start page's standard index.html.
  $page = new \Quanta\Common\Page($env);
  $vars = array('page' => &$page);
  $env->setData('page', $vars['page']);

  // Run the init hook.

  if (!isset($_REQUEST['ajax'])) {
    $env->hook('load_includes', $vars);
    $page->loadIncludes();
  }

  // Initialize doctor, if there is a request to do so.
  if (isset($doctor_cmd)) {
    $doctor = new Doctor($env, $doctor_cmd, $doctor_args);
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

  // Complete the boot process.
  exit();
