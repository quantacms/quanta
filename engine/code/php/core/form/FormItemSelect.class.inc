<?php

/**
 * Class FormItemString
 * This class represents a Form Item of type dropdown Select
 */
class FormItemSelect extends FormItem {

  public $type = 'select';

  /**
   * Renders the input item.
   * @return mixed
   */
  function render() {
    $rendered = '<select ' .
      ($this->isDisabled() ? 'disabled ' : '') .
      ($this->isRequired() ? 'required ' : '') .
      ('class = "' . $this->getClass() . '" ') .
      ('name = "' . $this->getName() . '" ') .
      ('id = "' . $this->getId() . '" ') .
      '>';

    foreach ($this->allowable_values as $k => $v) {
      $rendered .= '<option value="' . $k . '" ' . (($k == $this->getData('value')) ? 'selected' : '') . '>' . $v . '</option>';
    }
    $rendered .= '</select>';

    return $rendered;
  }

  function loadAttributes() {

  }
}
