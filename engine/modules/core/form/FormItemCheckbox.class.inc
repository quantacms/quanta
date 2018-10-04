<?php
/**
 * Class FormItemCheckbox
 * This class represents a Form Item of type tickable Checkbox
 */
class FormItemCheckbox extends FormItem {

  public $type = 'checkbox';

  /**
   * Renders the inpt item.
   * @return mixed
   */
  function render() {
    $value = $this->getValue();
    $rendered = '<input value="' . array_pop($value) . '" type="checkbox" ' .
      ($this->isDisabled() ? 'disabled ' : '') .
      ($this->isRequired() ? 'required ' : '') .
      ('class="' . $this->getClass() . '" ') .
      ($this->isChecked() ? 'checked ' : '') .
      ('name="' . $this->getName() . '" ') .
      ('id="' . $this->getId() . '" ') .
      '/> ' . $this->getData('title');

    return $rendered;
  }

  /**
   * Checkbox must have a default 0/1 state as the value is fixed.
   *
   * Load the default value for the input item.
   */
  public function loadDefault() {
    // If there is already a value set for the input item, ignore the default.
    $this->setData('default_value', !empty($single_value) ? $single_value : $this->getInputAttr('default'));
  }

  /**
   * @return mixed|null
   */
  public function isChecked() {
    return $this->getData('checked');
  }

  /**
   * We handle the "checked" attribute by comparing defualt and current value.
   */
  public function loadAttributes() {
    $this->setData('checked', $this->getCheckedValue() == $this->getDefaultValue());
    // TODO: Implement loadAttributes() method.
  }

  // For checkboxes we don't want to default title as label.

  /**
   * @return null
   */
  public function getLabel() {
    if (empty($this->getInputAttr('label'))) {
      return NULL;
    }
    return $this->getInputAttr('label');
  }

  /**
   * Return the checked value of a form item.
   *
   * @return mixed
   *   The checked value of the form item.
   */
  public function getCheckedValue() {
    return $this->getInputAttr('checked_value');
  }  

}
