<?php
/**
 * Class FormItemTime
 * This class represents a Form Item of type Hourly Time
 */
class FormItemTime extends FormItemString {

  function loadAttributes() {
    $this->addData('class', array('time-input'));
  }

}
