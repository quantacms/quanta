<?php
namespace Quanta\Common;
/**
 * Class DirList
 * This class is providing "node listing" features. It's used to scan a folder and
 * render the files and folders contained into it as nodes.
 */
class DirList extends ListObject {
  protected $scantype = \Quanta\Common\Environment::DIR_DIRS;

  public function start() {
    return;
  }

  /**
   * Generate the html of the list.
   */
  public function generateList() {

    $i = 0;
    // TODO: better management of this, including in NodeTemplate class etc.
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

      $i++;

      // If there is a limit set, break when passing it.
      if (!empty($this->limit) && $i > $this->limit) {
        break;
      }
      // Setup standard classes.
      $classes = array('dir-list-item', 'list-' . $this->getTpl() . '-item', 'list-item-' . $i, (($i % 2) == 0) ? 'list-item-even' : 'list-item-odd');

      // Check if the list item is the current / active one.
      if ($node->isCurrent() || ($this->getData('active_items') == $node->getName())) {
        $classes[] = 'list-selected';
      }
      if (isset($node_father) && $node_father->exists && ($node_father->getName() == $node->getName())) {
        $classes[] = 'list-selected-father';
      }
      foreach($this->replacements as $string => $replace) {
        $tpl = preg_replace("/" . str_replace('[', '\[', str_replace(']', '\]', $string)) . "/is", Api::string_normalize($replace), $tpl);
      }
      // TODO: not so beautiful to include a fake qTag. Can be done better.
      $list_item = QtagFactory::transformCodeTags($this->env, preg_replace("/\[LISTITEM\]/is", Api::string_normalize($dir_url), $tpl));
      $list_item = QtagFactory::transformCodeTags($this->env, preg_replace("/\[LISTNODE\]/is", Api::string_normalize($this->getNode()->getName()), $list_item));

      $vars = array(
        'list' => &$this,
        'list_item' => &$list_item,
        'list_item_counter' => $i,
        'list_item_classes' => &$classes,
      );
      $this->env->hook('list_item', $vars);

      $editable = $this->getData('editable');
      // Wrap in the inline editor.
      if (empty($editable) || $editable == 'true') {
        $list_item = NodeTemplate::wrap($this->env, $node, $list_item);
      }

      // If the "clean" attribute is not present, add some wrapping html.
      // Check if output should be list item or plain.
      if (empty($this->getData('clean'))) {
        $list_item = '<' . $this->getData('list_item_html_tag') . ' class="' . implode(' ', $classes) . '">' . $list_item . '</' . $this->getData('list_item_html_tag') . '>';
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

  /**
   * Compare nodes using custom criterias.
   *
   * @param Node $x
   *   The first node.
   *
   * @param Node $y
   *   The second node.
   *
   * @return int
   *   Returns 1 if first node is bigger than second. -1 otherwise.
   */
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
          $check = ($x->getDataJSON($this->sort) < $y->getDataJSON($this->sort));
          break;
      }

      return ($check) ? 1 : -1;
    }
  }

  /**
   * Adds a node item to the list.
   *
   * @param Node $node
   *   The node to be added to the list.
   */
  public function addItem($node) {
		$this->items[$node->getName()] = $node;
  }
}
