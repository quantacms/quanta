<?php
/**
 * Implementas hook_page_complete()
 *
 * @param $vars
 */
function wiki_page_complete($vars) {
  $vars['page']->html = transformCodeTags($vars['page']->env, $vars['page']->html);
}
