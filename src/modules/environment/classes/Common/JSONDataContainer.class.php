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
    $language = empty($this->getLanguage()) ? Localization::getLanguage($this->env) : $this->getLanguage();
    // An empty language is the neutral document, not a 'data_.json' with an
    // empty code in the name. The extension already reads lang '' as neutral
    // (api-contract.md §3), so leaving the legacy branch to build 'data_.json'
    // made the two implementations write different FILES for the same call —
    // pinned by files-db/tests/quanta/02_nodes.php.
    $suffix = (empty($language) || $language == \Quanta\Common\Localization::LANGUAGE_NEUTRAL)
      ? '' : ('_' . $language);
    $jsonpath = $this->path . '/data' . $suffix . '.json';

    // Unset attributes to ignore. Done before either write, so both persist
    // the same document.
    foreach ($ignore as $ignore_value) {
      if (isset($this->json->{$ignore_value})) {
        unset($this->json->{$ignore_value});
      }
    }

    $existed = is_dir($this->path);
    $opts = array('lang' => ($suffix == '') ? NULL : $language);
    $data = (array) json_decode(json_encode($this->json), TRUE);

    // quanta_db extension: locked, atomic, index-consistent write
    // (files-db/docs/api-contract.md §9).
    if (!$existed) {
      // Creating. Passing 'father' declares create intent: the directory is
      // reserved with a single atomic mkdir and the index knows about the node
      // on the ack, instead of whenever the watcher notices the bare mkdir
      // below. The father is taken from the path rather than from a subclass's
      // ->father, so this works for every JSONDataContainer.
      //
      // A name already used anywhere in the tree raises EXISTS, which is not
      // strict here, so it comes back as NULL and the legacy mkdir runs — the
      // duplicate is created exactly as it always was. That is deliberate: see
      // "Adopting the write API" in the contract. Turning it into a
      // user-visible error is a separate decision.
      $father_path = dirname($this->path);
      $father = basename($father_path);
      if (basename($this->path) === $this->name
          && $this->env->db()->resolvesTo($father, $father_path)) {
        $created = $this->env->db()->put($this->name, $data, $opts + array('father' => $father));
        if ($created) {
          return;
        }
      }
      if (!@mkdir($this->path, 0755, TRUE)) {
        throw new \Exception('Impossibile creare la directory: ' . $this->path . ' (Permesso negato o percorso non valido)');
      }
    }
    // Updating. The guard makes sure the globally-unique name resolves to THIS
    // container's folder; a name mismatch or any error uses the legacy write.
    elseif ($this->env->db()->resolvesTo($this->name, $this->path)) {
      $written = $this->env->db()->put($this->name, $data, $opts);
      if ($written) {
        return;
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
