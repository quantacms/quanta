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
   * Load the json attributes.
   */
  abstract function loadJSON();
  /**
   *  Update the json attributes based on the object's attribute.
   *  This is meant only to be extended.
   *
   *  @param array $ignore
   *    Attributes to ignore in the update process.
   */
  abstract function updateJSON(array $ignore = array());
  /**
   * Save a JSON dump of the data container.
   *
   * @param array $ignore
   *   Attributes to ignore in the save process.
   */
  protected function saveJSON(array $ignore = array()) {
    if (!is_dir($this->path)) {
      mkdir($this->path, 0755, TRUE) or die('Error. Cannot create dir: ' . $this->path);
    }

    $language = empty($this->getLanguage()) ? Localization::getLanguage($this->env) : $this->getLanguage();
    $suffix = ($language == \Quanta\Common\Localization::LANGUAGE_NEUTRAL) ? '' : ('_' . $language);
    $jsonpath = $this->path . '/data' . $suffix . '.json';

    // Unset attributes to ignore.
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
   *
   * @return string
   *   The folder name of the JSON container.
   */
  public function getName() {
    return $this->name;
  }

  /**
   * Sets the name (equal to folder name) of the container.
   *
   * @param string $name
   *   The folder name of the JSON container.
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
