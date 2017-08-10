  <?php
/**
 * @param $replace string
 * @param $tagname string
 * @param $attributes string
 * @param $string string
 * @param $page Page
 * @return mixed
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
  }
}