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

  public function setData($key, $value) {
    $this->data[$key] = $value;
  }

  public function getData($key, $default = NULL) {
    return (isset($this->data[$key]) ? $this->data[$key] : $default);
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