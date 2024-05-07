<?php
namespace Quanta\Qtags;

/**
 * Class FormItemCheckbox
 * This class represents a Form Item of type tickable Checkbox
 */
class FormItemRadio extends FormItemString {
  public $type = 'radio';
  public $label_position = Label::LABEL_ASIDE;

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
