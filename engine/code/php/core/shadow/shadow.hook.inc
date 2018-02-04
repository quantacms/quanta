<?php

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   Miscellaneous environment / page variables.
 */
function shadow_load_includes($env, $vars) {
  $env->addInclude('engine/code/php/core/shadow/js/shadow.js');
  $env->addInclude('engine/code/php/core/shadow/css/shadow.css');
}

/**
 * Implements hook_init().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function shadow_init(Environment $env, $vars) {
  // Check if there is a request to open a Shadow popup.
  if (isset($_REQUEST['shadow'])) {
    $shadow_json = json_decode($_REQUEST['shadow']);
    // Initialize the Shadow popup.
    $shadow = new Shadow($env, $shadow_json);

    // Check the requested context.
    $env->setContext($shadow_json->context);
    $env->setData('shadow', $shadow);
    // Render the Shadow popup.
    print $shadow->render();
    exit();
  }
}

/**
 * Implements hook_page_init().
 * Add the Shadow popup's wrapper to HTML.
 * // TODO: could be entirely done in JS.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function shadow_page_init($env, $vars) {
  $addcode = '<div id="shadow-outside"><div id="shadow-inside"></div><div id="shadow-item"></div></div>';
  $vars['page']->html = preg_replace("/\<\/body\>/", $addcode . '</body>', $vars['page']->html);
}
