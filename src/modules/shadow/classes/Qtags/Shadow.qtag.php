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
      case 'title':
        $string = $shadow->getData('title');
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
        // TODO: move to qtag.
        $buttons = '<div id="shadow-buttons">';
        foreach ($shadow->getData('buttons') as $action => $button) {
          $button_classes = 'shadow-submit';
          $button_title = $button;
          $button_link = '';
          if(is_array($button)){
            if(isset($button['title'])){
              $button_title = $button['title'];
            }
            if(isset($button['class'])){
              $button_classes = $button['class'];
            }
            if(isset($button['link'])){
              $button_link = "href='{$button['link']}'";
            }
          }
          $buttons .= '<a ' . $button_link . ' class="' . $button_classes .'" id="' . $action . '">' . $button_title . '</a>';
        }
        if(empty($shadow->getData('hide_cancel'))){
          $cancelTitle = $shadow->getData('delete_form') ? \Quanta\Common\Localization::translatableText($this->env,"No",'shadow-no') : \Quanta\Common\Localization::translatableText($this->env,"Cancel",'shadow-cancel'); 
          $buttons .= '<a class="shadow-cancel" id="cancel">'.$cancelTitle.'</a>';
        }
        $buttons .= '</div>';
        $string = $buttons;
        break;

      case 'redirect':
        $string = $shadow->getData('redirect');
        break;

      // Extra HTML that can be attached.
      case 'extra':
        $extra_arr = $shadow->getExtra();
        $extra_items = array();
        if (!empty($extra_arr)) {
          foreach ($extra_arr as $extra) {
            $extra_items[$extra['weight']] = $extra['content'];
          }
        }
        return implode('', $extra_items);
        break;
      default:

        $string = $shadow->getData($this->getTarget());
    }
    return $string;
  }
}
