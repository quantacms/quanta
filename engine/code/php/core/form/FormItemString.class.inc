<?php
/**
 * Class FormItemString
 * This class represents a Form Item of type dropdown Select
 */
class FormItemString extends FormItem {

  public $type = 'string';

  /**
   * Renders the input item.
   * @return mixed
   */
  function render() {
    $rendered = '';
    $rendered .= '<input value="' . $this->getCurrentValue() . '" type="' . $this->getHTMLType() . '" ' .
      ($this->isDisabled() ? 'disabled ' : '') .
      ($this->isRequired() ? 'required ' : '') .
      ('class="' . $this->getClass() . '" ') .
      ('placeholder="' . $this->getPlaceHolder() . '" ') .
      ('name="' . $this->getName() . '" ') .
      ('id="' . $this->getId() . '" ') .
      ($this->isMultiple() ? 'data-multiple ' : ' ') .
      '/>';

    return $rendered;
  }

  public function getHTMLType() {
    return 'text';
  }

  public function loadAttributes() {
    // TODO: Implement loadAttributes() method.
  }
}
