<?php

/*
 * Implements hook_init().
 */
function shadow_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/shadow/js/shadow.js');
  $vars['env']->addInclude('engine/code/php/core/shadow/css/shadow.css');
  if (isset($_REQUEST['shadow'])) {
    $shadow_hook = $_REQUEST['shadow'];
    // Use same hooks for node add and delete. TODO: better way? out of here?
    if ($shadow_hook == NODE_ACTION_ADD) {
      $shadow_hook = NODE_ACTION_EDIT;
    }
    $shadow = new Shadow($vars['env'], $_REQUEST['shadow']);
    $vars['env']->hook('shadow_' . $shadow_hook, array('shadow' => &$shadow));
    print $shadow->render();
    exit();
  }
}

/**
 * Implements hook_page_init().
 */
function shadow_page_init($vars) {
  $addcode = '<div id="shadow-outside"><div id="shadow-inside"></div><div id="shadow-item"></div></div>';
  $vars['page']->html = preg_replace("/\<\/body\>/", $addcode . '</body>', $vars['page']->html);
}