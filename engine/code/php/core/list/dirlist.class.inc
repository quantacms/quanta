<?php

/**
 * Class DirList
 * This class is providing "listing" features useful to scan a folder and
 * render the files and folders contained into it.
 */
class DirList extends ListObject {
  protected $scantype = DIR_DIRS;

  /**
   * Generate the html of the list.
   */
  public function theme() {
    $i = 0;
    $tpl = file_get_contents($this->getModulePath() . '/tpl/' . $this->getTpl() . '.tpl.php');
    /** @var Node $node_current */
    /** @var Node $node_father */
    $node_current = NodeFactory::current($this->env);
    $node_father = $node_current->getFather();

    // Cycle the subdirectories.
    foreach ($this->dirs as $dir_url => $node) {

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
      if ($node_father->exists && ($node_father->getName() == $node->getName())) {
        $classes[] = 'list-selected-father';
      }

      $list_item = transformCodeTags($this->env, preg_replace("/\[LISTITEM\]/is", string_normalize($dir_url), $tpl));
      $list_item = transformCodeTags($this->env, preg_replace("/\[LISTNODE\]/is", string_normalize($this->dir), $list_item));

      // If the "clean" attribute is not present, add some wrapping html.
      $this->env->hook('list_item', array(
        'list' => &$this,
        'list_item' => &$list_item,
      ));

      // Wrap in the inline editor.
      if (empty($this->getAttribute('nowrap'))) {
        $list_item = $node->wrap($list_item);
      }

      // Check if output should be list item or plain.
      if (empty($this->getAttribute('clean'))) {
        $list_item = '<li class="' . implode(' ', $classes) . '">' . $list_item . '</li>';
      }

      $this->list_items[] = $list_item;
    }

  }

  /**
   * Return all directories (nodes) found by the list.
   * @return array
   */
  public function getDirs() {
    return $this->dirs;
  }

  /**
   * Remove one of the loaded directories.
   * @param $dir
   */
  public function removeDir($dir) {
    unset($this->dirs[$dir]);
  }

}
