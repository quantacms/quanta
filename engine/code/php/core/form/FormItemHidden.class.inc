<?php
/**
 * Class FormItemHidden
 * This class represents a Form Item of type dropdown Select
 */
class FormItemHidden extends FormItem {
  public $type = 'hidden';

  /**
   * Renders the input item.
   * @return mixed
   */
  function render() {
    $rendered = '<input value="' . $this->getDefaultValue() .
      '" type="hidden" ' .
      ('name="' . $this->getName() . '" ') .
      ('id="' . $this->getId() . '" ') .
      '/>';
    return $rendered;
  }

  function loadAttributes() {
    $this->setData('label', NULL);
  }

  /**
   * @return null
   */
  function getLabel() {
    return NULL;
  }
}
