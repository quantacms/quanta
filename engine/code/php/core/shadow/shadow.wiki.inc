<?php
/**
 * @param $tagname string
 * @param $attribute
 * @param $string string
 * @param $env
 * @return mixed
 * @internal param string $replace
 * @internal param string $attributes
 * @internal param Page $page
 */
function wiki_SHADOW($tagname, $attribute, $string, $env) {
  $shadow = $env->getData('shadow');

  switch ($attribute) {
    case 'tab-titles':
      return $shadow->getData('tab_titles');
    break;
    case 'tab-contents':
      return $shadow->getData('tab_contents');
    break;
    case 'context':
      return $env->getContext();
      break;
    case 'node':
      return $shadow->getNode()->getName();
    break;
    case 'buttons':
      $buttons = '<div id="shadow-buttons">';
      foreach ($shadow->getData('buttons') as $action => $button) {
        $buttons .= '<a onClick="submitShadow()" id="' . $action . '">' . $button . '</a>';
      }
      $buttons .= '</div>';
      return $buttons;
    break;
    case 'redirect':
      return $shadow->getData('redirect');
    break;
    case 'extra':
      $html = '';
      $env->hook('shadow_extra', array('html' => &$html));
      return $html;
      break;
  }
}