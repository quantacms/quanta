<?php
/**
 * Class FormItemString
 * This class represents a Form Item of type dropdown Select
 */
class FormItemSubmit extends FormItem {


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

  // For checkboxes we don't want to default title as label.
  public function getLabel() {
    if (empty($this->getInputAttr('label'))) {
      return NULL;
    }
  }

}
