<?php
  include_once('core/environment/environment.module');
  $env = new Environment(NULL);
  $env->addInclude('engine/code/css/engine.css');
  $env->addInclude('engine/code/js/lib/jquery.js');
  $env->addInclude('engine/code/js/engine.js');

  $env->addInclude('engine/code/js/lib/jquery.knob.js');
  $env->addInclude('engine/code/js/lib/jquery.ui.widget.js');
  $env->addInclude('engine/code/js/lib/jquery.iframe-transport.js');
  $env->addInclude('engine/code/js/lib/zebra.js');
  $env->addInclude('engine/code/css/zebra.css');

  $env->startSession();
  $env->runModules();
  $env->hook('boot');

  // TODO: determine when to run cron.
  if (isset($_GET['cron'])) {
    $env->hook('cron');
  }

  if (isset($env->request_json->action)) {
    $env->hook('action_' . $env->request_json->action, array('data' => (array) $env->request_json));
  }
  $page = new Page($env, 'index.html');

  $env->hook('init', array('page' => &$page));
  if ($env->getData('content') == NULL) {
    $env->setData('title', '404 - Page not found');
    $env->setData('content', '404 - Page not found. This page doesn\'t exist or has been removed!');
  }

  $page->loadIncludes();
  $page->buildHTML();

  print $page->render();

  $env->hook('complete');

  exit();
?>