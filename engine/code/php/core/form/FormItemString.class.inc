<?php
/**
 * Class FormItemString
 * This class represents a Form Item of type dropdown Select
 */
class FormItemString extends FormItem {


  /**
   * Renders the input item.
   * @return mixed
   */
  function render() {
    $rendered = '<input value = "' . $this->getDefaultValue() . '" type = "' . $this->getHTMLType() . '" ' .
      ($this->isDisabled() ? 'disabled ' : '') .
      ($this->isRequired() ? 'required ' : '') .

      ('class = "' . $this->getClass() . '" ') .
      ('placeholder = "' . $this->getPlaceHolder() . '" ') .
      ('name = "' . $this->getName() . '" ') .
      ('id = "' . $this->getId() . '" ') .
      '/>';

    return $rendered;
  }

  public function getHTMLType() {
    return 'text';
  }

  public function getPlaceHolder() {
    return $this->getInputAttr('placeholder');
  }

  public function loadAttributes() {
    // TODO: Implement loadAttributes() method.
  }
}
