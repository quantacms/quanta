<?php
/**
 * Implementation of hook_init.
 * Look for all wiki_TAG functions, in all modules, and apply them to the code
 * to convert tags.
 *
 * @param $vars
 */
function wiki_page_init($vars) {

  preg_match_all("/\[(.*?)]/", $vars['page']->html, $matches);
  foreach ($matches[1] as $k => $tag_value) {
    $replace = '[' . $tag_value . ']';
    $tag = explode(':', $tag_value);
    if (count($tag) == 1) {
      $tag[1] = $vars['env']->getRequestedPath();
    }
    $tag_arr = explode('|', $tag[0]);
    $tag_name = $tag_arr[0];
    $attributes = explode('|', $tag[0]);
    unset($attributes[0]);
    if ($tag_name == strtoupper($tag_name) && function_exists('wiki_' . $tag_name)) {
      $func = 'wiki_' . $tag_name;
      $vars['page']->html = $func($replace, $attributes, $tag[1], $vars['page']->html, $vars['page']);
    }
  }
  if (!$vars['page']->getVariable('no_wiki')) {
    $vars['page']->html = wikize($vars['env'], $vars['page']->html);
  }
}