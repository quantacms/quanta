<?php
/**
 * Class JSONDataContainer
 * This class represents a JSON Data Container - aka a folder in the system
 * containing one or more data_xx.json files.
 * @see Node
 */
abstract class JSONDataContainer extends DataContainer {
  public $name;
  // public $context;
  public $json;

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
    $language = empty($this->getLanguage()) ? Locale::getLanguage($this->env) : $this->getLanguage();

    $jsonpath = $this->realpath . '/data_' . $language . '.json';

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

  /**
   * Gets the name (equal to folder name) of the node.
   * @return mixed
   */
  public function getName() {
    return $this->name;
  }

  /**
   * Sets the name (equal to folder name) of the container.
   * @param $name
   */
  public function setName($name) {
    $this->name = $name;
  }

}
