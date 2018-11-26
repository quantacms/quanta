<?php
namespace Quanta\Common;

/**
 * Class JSONDataContainer
 * This class represents a JSON Data Container - aka a folder in the system
 * containing one or more data_xx.json files.
 * @see Node
 */
abstract class JSONDataContainer extends DataContainer {
  public $name;
  public $path;

  public $jsonpath;
  public $dir;
  public $json;

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
    if (!is_dir($this->path)) {
      mkdir($this->path, 0755, TRUE) or die('Error. Cannot create dir: ' . $this->path);
    }

    $language = empty($this->getLanguage()) ? Localization::getLanguage($this->env) : $this->getLanguage();

    $suffix = ($language == LANGUAGE_NEUTRAL) ? '' : ('_' . $language);
    $jsonpath = $this->path . '/data' . $suffix . '.json';

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

  /**
   * Gets the full path of the json container.
   *
   * @return string
   *   The full path.
   */
  public function getPath() {
    return $this->path;
  }

}
