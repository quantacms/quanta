<?php
namespace Quanta\Qtags;

/**
 * Represents a Form item's label.
 */
class Label extends HtmlTag {
  const LABEL_ASIDE = 'aside';
  const LABEL_ON_TOP = 'on-top';
  const LABEL_HIDDEN = 'hidden';

  public $html_tag = 'label';
   
  /**
   * Render the Label.
   */
  public function render() {
    $this->html_params['for'] = $this->getTarget();
    $this->setHtmlBody($this->getAttribute('label_text'));
    if (!empty($this->getAttribute('required'))) {
      $required_attr = array();
      $this->html_body .= new FormItemRequiredStar($this->env, $required_attr, '*');
    }
    $label_rendered = parent::render();

    // Wrap the label in a div (on top) or span (aside) the form element.
    if (!empty($this->getAttribute('label_position')) && ($this->getAttribute('label_position') != self::LABEL_HIDDEN)) {
      $wrapper_attributes = array('class' => 'form-item-label form-item-label-' . $this->getAttribute('label_position'));
      $wrapper = new HtmlTag($this->env, $wrapper_attributes, $label_rendered);
      if ($this->getAttribute('label_position') == self::LABEL_ASIDE) {
        $wrapper->html_tag = 'span';
      }
      elseif ($this->getAttribute('label_position') == self::LABEL_ON_TOP) {
        $wrapper->html_tag = 'div';
      }
      $label_rendered = $wrapper->render();
    }
    return $label_rendered;
  }
}