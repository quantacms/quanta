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
      if (!@mkdir($this->path, 0755, TRUE)) {
        throw new \Exception('Impossibile creare la directory: ' . $this->path . ' (Permesso negato o percorso non valido)');
      }
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

    // quanta_db extension: locked, atomic, index-consistent write
    // (docs/files-db/api-contract.md §9). The realpath guard makes sure the
    // globally-unique name resolves to THIS container's folder; anything
    // else (new node dirs, name mismatch, errors) uses the legacy write.
    if (class_exists('QuantaDb') && is_dir($this->path)) {
      try {
        $qdb_path = \QuantaDb::path($this->name);
        if ($qdb_path !== NULL && realpath($qdb_path) === realpath($this->path)) {
          \QuantaDb::put($this->name, (array) json_decode(json_encode($this->json), TRUE), array(
            'lang' => ($suffix == '') ? NULL : $language,
          ));
          return;
        }
      }
      catch (\Throwable $e) {
        // Fall through to the legacy write.
      }
    }

    $fh = @fopen($jsonpath, 'w+');
    if ($fh === false) {
      throw new \Exception('Impossibile scrivere il file: ' . $jsonpath . ' (Permesso negato o directory non scrivibile)');
    }
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
