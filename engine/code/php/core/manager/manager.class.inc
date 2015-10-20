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
      if ($leaf->name != $this->node->name) {
        $lines[] = $this->renderLeaf($leaf);
      }
    }
    return '<ul id="tree-' . $path . '">' . implode('', $lines) . '</ul><script>refreshManagerLeaves();</script></ul>';
  }

  public function renderLeaf($leaf, $classes = NULL) {
    $selected = $leaf->hasChild($this->node->name);
    $is_father = ($leaf->name == $this->node->getFather()->name);

    if ($leaf->hasChildren()) {
      $classes .= " has-children";
    }
    return '<li><a href="#" rel="leaf-' . $leaf->name. '" class="manager-leaf ' . $classes . ' ' . ($selected ? 'selected' : '') . '">' . $leaf->title . '</a> ' . ($is_father ? ' (main category)' : ('<input type="checkbox" ' . ($selected ? 'checked' : '') . ' name="leaf-' . $leaf->name . '" id="leaf-' . $leaf->name . '" />')) .'</li>';
  }

}