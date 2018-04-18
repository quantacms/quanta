<?php

/**
 * Class DirList
 * This class is providing "node listing" features. It's used to scan a folder and
 * render the files and folders contained into it as nodes.
 */
class DirList extends ListObject {
  protected $scantype = DIR_DIRS;

  /**
   * Generate the html of the list.
   */
  public function generateList() {

    $i = 0;
    $tpl = file_get_contents($this->getModulePath() . '/tpl/' . $this->getTpl() . '.tpl.php');
    /** @var Node $node_current */
    $node_current = NodeFactory::current($this->env);
    /** @var Node $node_father */
    if ($node_current->exists) {
      $node_father = $node_current->getFather();
    }

    // Cycle the subdirectories.
    foreach ($this->items as $dir_url => $node) {

      /** @var Node $node */
      // If user can't access a node, completely exclude it from the list.
      if ($node->isForbidden()) {
        continue;
      }

      // Check if there is a filter set, and if it allows the item.
      if (!empty($this->list_filter) && !_access_filter($this->env, $this->list_filter, $node)) {
        continue;
      }
      $i++;

      // If there is a limit set, break when passing it.
      if (!empty($this->limit) && $i > $this->limit) {
        break;
      }
      // Setup standard classes.
      $classes = array('dir-list-item', 'list-' . $this->getTpl() . '-item', 'list-item-' . $i);

      // Check if the list item is the current / active one.
      if ($node->isCurrent() || ($this->getAttribute('active_items') == $node->getName())) {
        $classes[] = 'list-selected';
      }
      if (isset($node_father) && $node_father->exists && ($node_father->getName() == $node->getName())) {
        $classes[] = 'list-selected-father';
      }
      foreach($this->replacements as $string => $replace) {
        $tpl = preg_replace("/" . str_replace('[', '\[', str_replace(']', '\]', $string)) . "/is", string_normalize($replace), $tpl);
      }
      $list_item = transformCodeTags($this->env, preg_replace("/\[LISTITEM\]/is", string_normalize($dir_url), $tpl));
      $list_item = transformCodeTags($this->env, preg_replace("/\[LISTNODE\]/is", string_normalize($this->getNode()->getName()), $list_item));

      $vars = array(
        'list' => &$this,
        'list_item' => &$list_item,
      );
      $this->env->hook('list_item', $vars);

      $editable = $this->getAttribute('editable');

      // Wrap in the inline editor.
      if ($editable == 'true') {
        $list_item = $node->wrap($list_item);
      }

      // If the "clean" attribute is not present, add some wrapping html.
      // Check if output should be list item or plain.
      if (empty($this->getAttribute('clean'))) {
        $list_item = '<li class="' . implode(' ', $classes) . '">' . $list_item . '</li>';
      }

      $this->rendered_items[] = $list_item;
    }
  }

  /**
   * Remove one of the loaded directories.
   * @param $dir
   */
  public function removeDir($dir) {
    unset($this->items[$dir]);
  }

  public function sortBy($x, $y) {
    {
      // Which field to use for sorting.
      switch ($this->sort) {
        case 'name':
          $check = strcasecmp($x->getName(), $y->getName()) > 0;
          break;

        case 'title':
          $check = strcasecmp($x->getTitle(), $y->getTitle()) > 0;
          break;

        case 'time':
          $check = ($x->getTimestamp() < $y->getTimestamp());
          break;

        default:
          //Sort by Number DESC
          $check = ($x->getAttributeJSON($this->sort) < $y->getAttributeJSON($this->sort));
          break;
      }

      return ($check) ? 1 : -1;
    }
  }
}
