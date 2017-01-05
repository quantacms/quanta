<?php

/*
 * Implements hook_init().
 */
function shadow_init($vars) {
  $vars['env']->addInclude('engine/code/php/core/shadow/js/shadow.js');
  $vars['env']->addInclude('engine/code/php/core/shadow/css/shadow.css');

  if (isset($_REQUEST['shadow'])) {
    $shadow_json = json_decode($_REQUEST['shadow']);
    $vars['env']->setContext($shadow_json->context);
    $shadow = new Shadow($vars['env'], $shadow_json);
    $vars['env']->setData('shadow', $shadow);
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