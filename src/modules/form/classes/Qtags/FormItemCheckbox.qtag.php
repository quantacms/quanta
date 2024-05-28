<?php
namespace Quanta\Qtags;

/**
 * Class FormItemCheckbox
 * This class represents a Form Item of type tickable Checkbox
 */
class FormItemCheckbox extends FormItemString {
  public $type = 'checkbox';
  public $label_position = Label::LABEL_ASIDE;

    /**
   * Renders a form item as HTML.
   *
   * @return string
   *   The rendered form item.
   */
  public function render() {
    $checked = !empty($this->getAttribute('deafult-value')) ? $this->getAttribute('deafult-value') ==  $this->getDefaultValue() : $this->getDefaultValue() == true;
    if($checked){
      $this->html_params['checked'] = 'checked';
    }
    // Return the full rendered form item.
    return parent::render();
  }

  /**
   * @return mixed|null
   */
  public function isChecked() {
    return $this->getAttribute('checked');
  }

  /**
   * We handle the "checked" attribute by comparing defualt and current value.
   */
  public function loadAttributes() {
    $this->setData('checked', $this->getCheckedValue() == $this->getDefaultValue());
    // TODO: Implement loadAttributes() method.
  }

  /**
   * Return the checked value of a form item.
   *
   * @return mixed
   *   The checked value of the form item.
   */
  public function getCheckedValue() {
    return $this->getAttribute('checked_value');
  }
}
