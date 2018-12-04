<?php
namespace Quanta\Common;

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
      ('size="' . $this->getSize() . '" ') .
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
   * Gets the size of the input.
   *
   * @return string
   */
  public function getSize() {
    return $this->getData('size');
  }

  /**
   * Sets the size of the input.
   *
   * @param $size
   *   The input size.
   *
   * @return string
   */
  public function setSize($size) {
    $this->setData('size', $size);
  }


  /**
   * The HTML type of this input (could be text, date, etc.)
   *
   * @return string
   *   The HTML type.
   */
  public function getHTMLType() {
    return 'text';
  }

  /**
   * Load the Attributes of this item.
   */
  public function loadAttributes() {
    $size = $this->getInputAttr('size');
    if (empty($size)) {
      // TODO: default size for text inputs?
      $size = 20;
    }
    $this->setSize($size);
  }
}
