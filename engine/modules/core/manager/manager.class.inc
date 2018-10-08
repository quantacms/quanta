<?php

/**
 * Class Manager
 * This class represents a Node Manager used for categorizing nodes
 * and symlinking them via a dedicated UI.
 */
class Manager extends DataContainer {
  /** @var Node $node */
  public $node;

  /**
   * Constructs a Node manager object.
   *
   * @param Environment $env
   *   The Environment.
   * @param Node $node
   *   The Node to manage.
   */
  public function __construct(&$env, $node = NULL) {
    $this->env = $env;
    $this->node = $node;
  }

  /**
   * Render the node manager tree.
   *
   * @param string $path
   *   The path.
   *
   * @return string
   *   The rendered node manager tree.
   */
  public function renderTree($path = 'root') {
    $list = new DirList($this->env, $path, 'simple', array('symlinks' => 'no'));
    $leaves = $list->getItems();
    $lines = array();
    foreach ($leaves as $leaf) {

      if (!empty($leaf->name) && $leaf->name != 'home' && $leaf->name != 'files') {
        $lines[] = $this->renderLeaf($leaf);
      }
    }
    return '<ul id="tree-' . $path . '">' . implode('', $lines) . '</ul><script>refreshManagerLeaves();</script></ul>';
  }

  /**
   * Renders a leaf of the tree.
   *
   * @param Node $leaf
   *   The leaf node to render.
   *
   * @return string
   *   The rendered leaf.
   */
  public function renderLeaf($leaf) {
    $selected = (!empty($this->node->name) && $this->env->getContext() != NODE_ACTION_ADD) ? $leaf->hasChild($this->node->name) : FALSE;
    $is_father = (!empty($this->node->getFather())) ? ($leaf->name == $this->node->getFather()->name) : FALSE;
    $classes = array('manager-leaf');
    if ($leaf->hasChildren()) {
      $classes[] = "has-children";
    }

    if (!empty($this->node->name) && ($leaf->name == $this->node->name)) {
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