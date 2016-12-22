<?php
  include_once('core/environment/environment.module');
  $env = new Environment(NULL);
  $env->startSession();
  $env->runModules();
  $env->hook('boot');

  // TODO: determine when to run cron.
  if (isset($_GET['cron'])) {
    $env->hook('cron');
  }
  $env->checkActions();

  $page = new Page($env, 'index.html');
  $env->setData('page', $page);
  $env->hook('init', array('page' => &$page));
  $page->loadIncludes();
  $page->buildHTML();
  print $page->render();
	$env->hook('complete');
	exit();
?>
