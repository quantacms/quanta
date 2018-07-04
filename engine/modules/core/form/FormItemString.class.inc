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
    // TODO: data items should go in an array and be rendered all together
    // in order to be extendable by subclasses...

    $rendered .= '<input value="' . str_replace('"', '&#34;', $this->getCurrentValue()) . '" type="' . $this->getHTMLType() . '" ' .
      ($this->isDisabled() ? 'disabled ' : '') .
      ($this->isRequired() ? 'required ' : '') .
      ('class="' . $this->getClass() . '" ') .
      ('placeholder="' . $this->getPlaceHolder() . '" ') .
      ('name="' . $this->getName() . '" ') .
      ('id="' . $this->getId() . '" ') .
      ($this->isMultiple() ? 'data-multiple ' : ' ') .
      ($this->isDistinct() ? 'data-distinct ' : ' ') .
      ($this->isMultiple() ? 'data-limit="' . $this->getData('limit'). '" ' : ' ') .
      (!empty($this->getInputAttr('node')) ? ('data-node="' . $this->getInputAttr('node')) . '" ' : ' ') .
      '/>';

    return $rendered;
  }

  /**
   * @return string
   */
  public function getHTMLType() {
    return 'text';
  }

  public function loadAttributes() {
    // TODO: Implement loadAttributes() method.
  }
}
