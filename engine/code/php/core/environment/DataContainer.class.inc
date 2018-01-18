<?php

abstract class DataContainer {
  /**
   * @var Environment $env
   */
  public $env;
  public $data = array();
  public $path;
  // TODO: dismiss fullpath.
  public $fullpath;
  public $realpath;
  public $dir;
  public $language;

  public function setData($key, $value) {
    $this->data[$key] = $value;
  }

  public function getData($key, $default = NULL) {
    return (isset($this->data[$key]) ? $this->data[$key] : $default);
  }

  public function addData($key, $value, $before = FALSE) {
    $curr = $this->getData($key);

    if (empty($curr)) {
      $this->setData($key, $value);
      return;
    }
    if (gettype($value) != gettype($curr)) {
      print "ERROR: different value type for setdata. " . var_export($curr, 1) . ' and ' . var_export($value, 1) . ' have different data types!';
    }

    if (is_array($value)) {
      $sum = ($before) ? (array_merge($value, $curr)) : (array_merge($curr, $value));

    }
    elseif (is_numeric($value)) {
      $sum = $curr + $value;
    }
    elseif (is_string($value)) {
      $sum = ($before) ? ($value . $curr) : ($curr . $value);
    }
    else {
      print "UNKNOWN DATA TYPE: " . $value;
    }
    $this->setData($key, $sum);
  }

  /**
   * Gets the name (equal to folder name) of the container.
   * @return mixed
   */
  public function getLanguage() {

    return $this->language;
  }

  /**
   * Sets the name (equal to folder name) of the container.
   * @param $language
   * @internal param $name
   */
  public function setLanguage($language) {
    $this->language = $language;
  }

}