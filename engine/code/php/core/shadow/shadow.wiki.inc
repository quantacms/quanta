<?php
/**
 * @param $replace string
 * @param $tagname string
 * @param $attributes string
 * @param $string string
 * @param $page Page
 * @return mixed
 */
function wiki_SHADOW($replace, $tagname, $attributes, $string, $page) {
  $string = str_replace('[SHADOW:tab-titles]', $page->getVariable('tab_titles'), $string);
  $string = str_replace('[SHADOW:tab-contents]', $page->getVariable('tab_contents'), $string);

  $buttons = '';
  foreach ($page->getVariable('buttons') as $action => $button) {
    $buttons .= '<a onClick="shadowSubmit()" id="' . $action . '">' . $button . '</a>';
  }
  $string = str_replace('[SHADOW:buttons]', '<div id="shadow-buttons">' . $buttons . '</div>', $string);

  return $string;
}