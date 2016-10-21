<?php

/**
 * Class Manager
 * This class represents a Manager for categorizing nodes.
 */
class Manager {

  public $node;
  /**
   * Constructs a manager object.
   * @param $env
   * @param $name
   */
  public function __construct(&$env, $node = NULL) {
    $this->env = $env;
    $this->node = $node;
  }

  public function renderTree($path = 'root') {
    $list = new DirList($this->env, $path, 'simple', array('symlinks' => 'no'));
    $leaves = $list->getDirs();
    $lines = array();

    foreach ($leaves as $leaf) {
      if ($leaf->name != 'home' && $leaf->name != 'files') {
        $lines[] = $this->renderLeaf($leaf);
      }
    }
    return '<ul id="tree-' . $path . '">' . implode('', $lines) . '</ul><script>refreshManagerLeaves();</script></ul>';
  }

  /**
   * Renders a leaf of the tree.
   * @param $leaf
   * @return string
   */
  public function renderLeaf($leaf) {
    $selected = $leaf->hasChild($this->node->name);
    $is_father = ($leaf->name == $this->node->getFather()->name);
    $classes = array('manager-leaf');
    if ($leaf->hasChildren()) {
      $classes[] = "has-children";
    }

    if ($leaf->name == $this->node->name) {
      $txt = '(current node)';
    } elseif ($is_father) {
      $txt = '(main category)';
      $classes[] = 'manager-main-category';
    } else {
      $txt = '<input value="' . $leaf->name . '" type="checkbox" ' . ($selected ? 'checked' : '') . ' name="leaf-' . $leaf->name . '" id="leaf-' . $leaf->name . '" />';
    }
    return '<li><a href="#" rel="leaf-' . $leaf->name. '" class="' . implode(' ', $classes) . ' ' . ($selected ? 'selected' : '') . '">' . ($leaf->title == '' ? $leaf->name : $leaf->title) . '</a> ' . $txt .'</li>';
  }

}