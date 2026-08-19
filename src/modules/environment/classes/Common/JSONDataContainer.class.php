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
    // empty code in the name. The node database reads lang '' as neutral
    // (api-contract.md §3), and both of its implementations name the same file
    // for the same call — pinned by files-db/tests/quanta/02_nodes.php.
    $neutral = (empty($language) || $language == \Quanta\Common\Localization::LANGUAGE_NEUTRAL);

    // Unset attributes to ignore, before the write.
    foreach ($ignore as $ignore_value) {
      if (isset($this->json->{$ignore_value})) {
        unset($this->json->{$ignore_value});
      }
    }

    $opts = array(
      'lang' => $neutral ? NULL : $language,
      // 'at' is where this container lives, which is not always where its NAME
      // resolves: a JSONDataContainer can be built from an explicit path. The
      // database writes the directory named here and uses the name only to
      // decide whether it can take the fast, locked path.
      'at' => $this->path,
    );
    if (!is_dir($this->path)) {
      // Creating. 'father' declares create intent: the directory is reserved
      // with a single atomic mkdir and the node is known to the index on the
      // ack, instead of whenever a watcher notices a bare mkdir. The father is
      // taken from the path rather than from a subclass's ->father, so this
      // works for every JSONDataContainer.
      $opts['father'] = basename(dirname($this->path));
      // if_exists => 'ignore' keeps Quanta's historical answer to a name that
      // is already used somewhere else in the tree: create the directory
      // anyway, and let the resolver warn about the duplicate when it next
      // trips over it. The node database would otherwise refuse with EXISTS —
      // it reserves names tree-wide — and turning that refusal into a
      // user-visible error is a product decision this line is deliberately
      // NOT making. Drop the option to make it.
      $opts['if_exists'] = 'ignore';
    }

    $data = (array) json_decode(json_encode($this->json), TRUE);
    $this->env->db()->put($this->name, $data, $opts);
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
