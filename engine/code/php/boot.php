<?php
  // Include the Environment module.
  require_once('core/environment/environment.module');

  // Include the Cache module.
  require_once('core/cache/cache.module');

  // Create a new Environment.
  $env = new Environment(NULL);

  if (!isset($_GET['doctor']) || !($_GET['doctor'] == 'setup')) {
    // Check if the site is installed.
    $env->checkInstalled();
  }

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
  $page = new Page($env, 'index.html');
  $vars = array('page' => &$page);

  $env->setData('page', $page);

  // Run the init hook.

  if (!isset($_REQUEST['ajax'])) {
    $env->hook('load_includes', $vars);
    $page->loadIncludes();
  }

  // TODO: determine when to run setup.
  if (isset($_GET['doctor']) && $_GET['doctor'] == 'setup') {
    // Create the doctor.
    $doctor = new Doctor($env);
    // Run the setup.
    $doctor->runSetup();
    exit;
  }

  // TODO: determine when to run doctor.
  if (isset($_GET['doctor'])) {
    $doctor = new Doctor($env);
    $doctor->runDoctor();
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
