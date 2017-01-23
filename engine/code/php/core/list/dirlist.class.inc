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
    foreach ($this->dirs as $dir_url => $node) {
      // If user can't access a node, completely exclude it from the list.
      if ($node->isForbidden()) {
        continue;
      }
      $i++;
      // If there is a limit set, break when passing it.
      if (!empty($this->limit) && $i > $this->limit) {
        break;
      }
      $classes = array('dir-list-item', 'list-' . $this->getTheme() . '-item', 'list-item-' . $i);
      if ($node->isCurrent()) {
        $classes[] = 'list-selected';
      }
      $node_father = NodeFactory::current($this->env)->getFather();
      if ($node_father->exists && ($node_father->name == $node->name)) {
        $classes[] = 'list-selected-father';
      }


      $tpl = file_get_contents($this->getModulePath() . '/tpl/' . $this->getTheme() . '.tpl.php');

      $list_item = transformCodeTags($this->env, preg_replace("/\[LISTITEM\]/is", string_normalize($dir_url), $tpl));
      // If the "clean" attribute is not present, add some wrapping html.
      $this->env->hook('list_item', array(
        'list' => &$this,
        'list_item' => &$list_item,
      ));
      if (empty($this->getAttribute('clean'))) {
        $list_item = $node->wrap($list_item);
        $list_item = '<li class="' . implode(' ', $classes) . '">' . $list_item . '</li>';
      }
      $this->list_items[] = $list_item;
    }
  }

  public function getDirs() {
    return $this->dirs;
  }

}
