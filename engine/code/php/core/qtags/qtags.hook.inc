<?php
/**
 * Implements hook_page_complete()
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function qtags_page_complete($env, $vars) {
  $vars['page']->html = transformCodeTags($vars['page']->env, $vars['page']->html);
}
