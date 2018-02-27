<?php
/**
 * Implements qtag SHADOW.
 *
 * Renders a Shadow (An overlay input form)
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param mixed $target
 *   The Shadow attribute to show.
 *
 * @param array $attributes
 *   Attributes of the tag.
 *
 * @return string
 *   The rendered Shadow.
 */
function qtag_SHADOW($env, $target, $attributes) {
  $shadow = $env->getData('shadow');
  $string = NULL;
  switch ($target) {
    case 'tab-titles':
      $string = $shadow->getData('tab_titles');
      break;
    case 'tab-contents':
      $string = $shadow->getData('tab_contents');
      break;
    case 'context':
      $string = $env->getContext();
      break;
    case 'node':
      $string = $shadow->getNode()->getName();
      break;
    case 'buttons':
      $buttons = '<div id="shadow-buttons">';
      foreach ($shadow->getData('buttons') as $action => $button) {
        $buttons .= '<a onClick="submitShadow()" id="' . $action . '">' . $button . '</a>';
      }
      $buttons .= '</div>';
      $string = $buttons;
      break;

    case 'redirect':
      $string = $shadow->getData('redirect');
      break;

    // Extra HTML that can be attached.
    case 'extra':
      $html = '';
      $vars = array('html' => &$html);
      $env->hook('shadow_' . $env->getContext() . '_extra', $vars);
      $string = $html;
      break;
  }
  return $string;
}