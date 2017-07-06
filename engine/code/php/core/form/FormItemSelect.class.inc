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
      ('class="' . $this->getClass() . '" ') .
      ('name="' . $this->getName() . '" ') .
      ('id="' . $this->getId() . '" ') .
      ($this->isMultiple() ? 'data-multiple ' : ' ') .
      ($this->isDistinct() ? 'data-distinct ' : ' ') .
      ($this->isMultiple() ? 'data-limit="' . $this->getData('limit'). '" ' : ' ') .
      '>';


    $value = $this->getCurrentValue();

    foreach ($this->allowable_values as $k => $v) {
      $rendered .= '<option value="' . $k . '" ' . ($value == $k ? 'selected' : '') . '>' . $v . '</option>';
    }
    $rendered .= '</select>';

    return $rendered;
  }

  function loadAttributes() {

  }
}
