<?php
namespace Quanta\Common;

/**
 * Class FormItemString
 * This class represents a Form Item of type dropdown Select
 */
class FormItemDate extends FormItemString {
  const INPUT_DATE_NOW = 'NOW';
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

  /**
   * Parse a date in a specific format.
   *
   * @param $date
   *   The input date.
   * @param null $format
   *   The date format to convert the date into.
   *
   * @return false|string
   *   The parsed date.
   */
  public function parseDate($date, $format = NULL) {
    if (empty($format)) {
      $format = $this->getDateFormat();
    }
    if ($date == self::INPUT_DATE_NOW) {
      $time = time();
    }
    else {
      $time = strtotime($date);
    }
    return date($format, $time);
  }

  /**
   * Get the date format of the item.
   *
   * @return string
   *   The date format of the input item
   */
  public function getDateFormat() {
    return $this->format;
  }

  /**
   * Gets the HTML type of the Input item.
   *
   * @return string
   *   The Html type.
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
