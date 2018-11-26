<?php
namespace Quanta\Common;

/**
 * Class FormItemTime
 * This class represents a Form Item of type Hourly Time
 */
class FormItemTime extends FormItemString {
  public $type = 'time';

  function loadAttributes() {
    $this->addData('class', array('time-input'));
  }

}
