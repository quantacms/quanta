<?php
/**
 * Class FormItemString
 * This class represents a Form Item of type dropdown Select
 */
class FormItemSubmit extends FormItem {
  public $type = 'submit';

  /**
   * Renders the input item.
   * @return mixed
   */
  function render() {
    $rendered = '<input value = "' . $this->getDefaultValue() . '" type = "submit" ' .
      ($this->isDisabled() ? 'disabled ' : '') .
      ('class = "' . $this->getClass() . '" ') .
      ('name = "' . $this->getName() . '" ') .
      ('id = "' . $this->getId() . '" ') .
      '/>';

    return $rendered;
  }

  function loadAttributes() {
    // TODO: Implement loadAttributes() method.
  }

  /**
   * Gets the label for the submit button.
   * @return null
   */
  public function getLabel() {
    if (empty($this->getInputAttr('label'))) {
      return NULL;
    }
    return $this->getInputAttr('label');
  }


}
