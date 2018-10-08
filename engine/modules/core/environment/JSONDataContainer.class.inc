<?php
/**
 * Class JSONDataContainer
 * This class represents a JSON Data Container - aka a folder in the system
 * containing one or more data_xx.json files.
 * @see Node
 */
abstract class JSONDataContainer extends DataContainer {
  public $name;
  public $realpath;
  public $jsonpath;
  public $dir;
  public $json;
  public $path;

  /**
   *  This is meant only to be extended.
   */
  abstract function loadJSON();

  /**
   *  This is meant only to be extended.
   * @param array $ignore
   */
  abstract function updateJSON($ignore = array());

  /**
   * Save the JSON dump of the data container.
   * @param array $ignore
   */
  protected function saveJSON($ignore = array()) {
    if (!is_dir($this->realpath)) {
      mkdir($this->realpath, 0755, TRUE) or die('Error. Cannot create dir: ' . $this->realpath);
    }

    $language = empty($this->getLanguage()) ? Localization::getLanguage($this->env) : $this->getLanguage();

    $suffix = ($language == LANGUAGE_NEUTRAL) ? '' : ('_' . $language);
    $jsonpath = $this->realpath . '/data' . $suffix . '.json';

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
