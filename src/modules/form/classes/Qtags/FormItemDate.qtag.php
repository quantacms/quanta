<?php
namespace Quanta\Qtags;

/**
 * Class FormItemString
 * This class represents a Form Item of type dropdown Select
 */
class FormItemDate extends FormItemString {
  const INPUT_DATE_NOW = 'NOW';
  public $type = 'date';
  public $format = 'Y-m-d';

  /**
   * Alter current date to use Y-m-d as required from HTML5.
   *
   * @return string
   *   The parsed date.
   */
  public function getDefaultValue() {
    $curr = parent::getDefaultValue();
    return $this->parseDate($curr, 'Y-m-d');
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
   * Renders the input item.
   * @return mixed
   */
  function render() {
    // TODO: data items should go in an array and be rendered all together
    // in order to be extendable by subclasses...
    if (!empty($this->getAttribute('date_format'))) {
      $this->format = $this->getAttribute('date_format');
    }
    if (!empty($this->getAttribute('date_min'))) {
      $this->html_params['min'] = $this->parseDate($this->getAttribute('date_min'), 'Y-m-d');
    }
    if (!empty($this->getAttribute('date_max'))) {
      $this->html_params['max'] = $this->parseDate($this->getAttribute('date_max'), 'Y-m-d');
    }
    return parent::render();
  }
}
