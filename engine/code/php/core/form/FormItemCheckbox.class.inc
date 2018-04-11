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
    $rendered = '<input value="' . $this->getDefaultValue() . '" type = "checkbox" ' .
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
   * @return mixed|null
   */
  public function isChecked() {
    return $this->getData('checked');
  }

  /**
   * We handle the "checked" attribute by comparing defualt and current value.
   */
  public function loadAttributes() {
    $value = $this->getValue();
    $this->setData('checked', $this->getDefaultValue() == array_pop($value));
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
  }

}
