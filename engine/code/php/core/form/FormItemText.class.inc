<?php

/**
 * Class FormItemText
 * This class represents a Form Item of type textarea
 */
class FormItemText extends FormItem {
  public $type = 'text';

  function loadAttributes() {
    $this->setData('label', $this->getData('title'));
    $this->addData('class', array((!empty($this->getInputAttr('wysiwyg'))) ? 'wysiwyg' : 'not-wysiwyg'));

    $cols = $this->getInputAttr('cols');
    $rows = $this->getInputAttr('rows');

    $this->setData('cols', (($cols > 0) ? $cols : 50));
    $this->setData('rows', (($rows > 0) ? $rows : 50));
  }

  /**
   * Renders the input item.
   * @return mixed
   */
  public function render() {
    $rendered = '<textarea ' .
      ($this->isDisabled() ? 'disabled ' : '') .
      ($this->isRequired() ? 'required ' : '') .
      ('placeholder="' . $this->getPlaceHolder() . '" ') .
      ('class="' . $this->getClass() . '" ') .
      ('cols="' . $this->getData('cols') . '" ') .
      ('rows="' . $this->getData('rows') . '" ') .
      ('name="' . $this->getName() . '" ') .
      ('id="' . $this->getId() . '" ') .
      ($this->isMultiple() ? 'data-multiple ' : ' ') .
      '>' .
      htmlentities($this->getDefaultValue()) .
      '</textarea>';

    return $rendered;
  }

}
