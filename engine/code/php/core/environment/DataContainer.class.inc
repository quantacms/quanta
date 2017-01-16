<?php

abstract class DataContainer {
  /**
   * @var Environment
   */
  public $env;
  public $data = array();
  public $path;
  public $fullpath;
  public $realpath;
  public $json;
  public $dir;

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
   *  This is meant only to be extended.
   */
  abstract function loadJSON();


  /**
   *  This is meant only to be extended.
   */
  abstract function updateJSON($ignore = array());

    /**
   * Save the JSON dump of the data container.
   */
  protected function saveJSON($ignore = array()) {

    if (!is_dir($this->realpath)) {
      mkdir($this->realpath) or die('cannot create dir: ' . $this->realpath);
    }
    $jsonpath = $this->realpath . '/data.json';
    foreach ($ignore as $ignore_value) {
      if (isset($this->json->{$ignore_value})) {
        unset($this->json->{$ignore_value});
      }
    }
    $fh = fopen($jsonpath, 'w+');
    fwrite($fh, json_encode($this->json));
    fclose($fh);

    unset($user_json);
  }

}