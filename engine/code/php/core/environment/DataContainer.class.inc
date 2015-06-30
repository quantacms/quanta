<?php

abstract class DataContainer {
  /**
   * @var Environment
   */
  public $env;
  public $data = array();

  public function setData($key, $value) {
    $this->data[$key] = $value;
  }

  public function getData($key) {
    return (isset($this->data[$key]) ? $this->data[$key] : NULL);
  }

}