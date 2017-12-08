<?php
	include_once('core/environment/environment.module');

  $env = new Environment(NULL);
  $env->startSession();
  $env->runModules();
  $env->hook('boot');
  $env->checkActions();
  // Start page's standard index.html.
  $page = new Page($env, 'index.html');
  $env->setData('page', $page);
  $env->hook('init', array('page' => &$page));
  $page->loadIncludes();
  $page->buildHTML();
  // TODO: determine when to run doctor.
  if (isset($_GET['doctor'])) {
    $doctor = new Doctor($env);
    $doctor->runAllTasks();
  }
  else {
    print $page->render();
  }
  $env->hook('complete');
	exit();
?>
