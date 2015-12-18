<?php
/**
 * @param $replace string
 * @param $tagname string
 * @param $attributes string
 * @param $string string
 * @param $page Page
 * @return mixed
 */
function wiki_SHADOW($tagname, $attribute, $string, $page) {
  switch ($attribute) {
    case 'tab-titles':
      return $page->getData('tab_titles');
    break;
    case 'tab-contents':
      return $page->getData('tab_contents');
    break;
    case 'buttons':
      $buttons = '<div id="shadow-buttons">';
      foreach ($page->getData('buttons') as $action => $button) {
        $buttons .= '<a onClick="shadowSubmit()" id="' . $action . '">' . $button . '</a>';
      }
      $buttons .= '</div>';
      return $buttons;
      break;
  }
}