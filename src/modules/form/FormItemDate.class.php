<?php
namespace Quanta\Common;

define('INPUT_DATE_NOW', 'NOW');
/**
 * Class FormItemString
 * This class represents a Form Item of type dropdown Select
 */
class FormItemDate extends FormItemString {
  public $type = 'date';
  public $format = 'Y-m-d';

  function loadAttributes() {
    $this->addData('class', array('hasDatePicker'));
    parent::loadAttributes();
    if (!empty($this->getInputAttr('date_format'))) {
      $this->format = $this->getInputAttr('date_format');
    }
    if (!empty($this->getInputAttr('date_min'))) {
      $this->setData('min', $this->parseDate($this->getInputAttr('date_min'), 'Y-m-d'));
    }
    if (!empty($this->getInputAttr('date_max'))) {
      $this->setData('max', $this->parseDate($this->getInputAttr('date_max'), 'Y-m-d'));
    }
  }

  public function parseDate($date, $format = NULL) {
    if (empty($format)) {
      $format = $this->getDateFormat();
    }
    if ($date == INPUT_DATE_NOW) {
      $time = time();
    }
    else {
      $time = strtotime($date);
    }
    return date($format, $time);
  }

  public function getDateFormat() {
    return $this->format;
  }
  /**
   * @return string
   */
  public function getHTMLType() {
    return 'date';
  }

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
      (!empty($this->getInputAttr('node')) ? (' data-node="' . $this->getInputAttr('node')) . '" ' : '') .
      (!empty($this->getData('min')) ? (' min="' . $this->getData('min')) . '" ' : '') .

      (!empty($this->getData('max')) ? (' max="' . $this->getData('max')) . '" ' : '') .

      '/>';

    return $rendered;
  }
}
