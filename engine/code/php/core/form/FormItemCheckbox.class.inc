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
    $rendered = '<input value = "' . $this->getDefaultValue() . '" type = "checkbox" ' .
      ($this->isDisabled() ? 'disabled ' : '') .
      ($this->isRequired() ? 'required ' : '') .
      ('class = "' . $this->getClass() . '" ') .
      ($this->isChecked() ? 'checked' : '') .
      ('name = "' . $this->getName() . '" ') .
      ('id = "' . $this->getId() . '" ') .
      '/> ' . $this->getData('title');

    return $rendered;
  }

  public function isChecked() {
    return $this->getData('checked');
  }

  public function loadAttributes() {
    $this->setData('checked', $this->getDefaultValue() == $this->getValue());
    // TODO: Implement loadAttributes() method.
  }

  // For checkboxes we don't want to default title as label.
  public function getLabel() {
    if (empty($this->getInputAttr('label'))) {
      return NULL;
    }
  }

}
