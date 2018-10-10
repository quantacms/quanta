<?php

/**
 * Class DataContainer
 */
abstract class DataContainer {
  /**
   * @var Environment $env
   */
  protected $env;
  protected $data = array();
  public $language;

  /**
   * @param $key
   * @param $value
   */
  public function setData($key, $value) {
    $this->data[$key] = $value;
  }

  /**
   * @param $key
   * @param null $default
   * @return mixed|null
   */
  public function getData($key, $default = NULL) {
    return (isset($this->data[$key]) ? $this->data[$key] : $default);
  }

  /**
   * @param $key
   * @param $value
   * @param bool $before
   */
  public function addData($key, $value, $before = FALSE) {
    $curr = $this->getData($key);

    if (empty($curr)) {
      $this->setData($key, $value);
      return;
    }
    if (gettype($value) != gettype($curr)) {
      new Message($this->env,t('ERROR: different value type for setdata. %first and %second have different data types!', array('%first' => var_export($curr, 1), '%second' => var_export($value, 1))), MESSAGE_ERROR);
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
      new Message($this->env, "UNKNOWN DATA TYPE: %value", array('%value' => $value), MESSAGE_ERROR);
      $sum = NULL;
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