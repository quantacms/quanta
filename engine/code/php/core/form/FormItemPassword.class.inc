<?php
/**
 * Class FormItemString
 * This class represents a Form Item of type dropdown Select
 */
class FormItemPassword extends FormItem {

  /**
   * Renders the input item.
   * @return mixed
   */
  function render() {
    $rendered = '<input value = "' . $this->getDefaultValue() . '" type = "password" ' .
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
}
