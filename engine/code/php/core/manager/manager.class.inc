<?php

/**
 * Class Node
 * This class represents a Node (corrisponding to a folder in the file system).
 * This is the core of the engine.
 */
class Manager {

  public $node;
  /**
   * Constructs a manager object.
   * @param $env
   * @param $name
   * @param null $father
   */
  public function __construct(&$env, $node = NULL) {
    $this->env = $env;
    $this->node = $node;
  }

  public function renderTree($path = 'editoriale') {

    $list = new DirList($this->env, $path, 'simple');
    $leaves = $list->getDirs();
    $lines = array();
    foreach ($leaves as $leaf) {
      $selected = $leaf->hasChild($this->node->name);
      $lines[] = '<li><a href="#" rel="leaf-' . $leaf->name. '" class="manager-leaf ' . ($selected ? 'selected' : '') . '">' . $leaf->title . '</a><input type="checkbox" ' . ($selected ? 'checked' : '') . ' name="leaf-' . $leaf->name . '" id="leaf-' . $leaf->name . '" /></li>';
    }
    return '<ul id="tree-' . $path . '">' . implode('', $lines) . '</ul><script>refreshManagerLeaves();</script></ul>';
  }

}