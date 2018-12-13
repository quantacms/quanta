<?php
namespace Quanta\Qtags;
/**
 * Renders an element of Shadow, Quanta's overlay input form.
 */
class Shadow extends Content {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $shadow = $this->env->getData('shadow');
    $string = NULL;
    // The shadow element to display.
    switch ($this->getTarget()) {
      case 'tab-titles':
        $string = $shadow->getData('tab_titles');
        break;
      case 'tab-contents':
        $string = $shadow->getData('tab_contents');
        break;
      case 'context':
        $string = $this->env->getContext();
        break;
      case 'node':
        $string = $shadow->getNode()->getName();
        break;
      case 'buttons':
        $buttons = '<div id="shadow-buttons">';
        foreach ($shadow->getData('buttons') as $action => $button) {
          $buttons .= '<a class="shadow-submit" id="' . $action . '">' . $button . '</a>';
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
        $this->env->hook('shadow_' . $this->env->getContext() . '_extra', $vars);
        $string = $html;
        break;
    }
    return $string;
  }
}
