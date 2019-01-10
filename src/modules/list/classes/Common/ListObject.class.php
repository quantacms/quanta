<?php
namespace Quanta\Common;

/**
 * Class DirList
 * This class is providing "listing" features useful to scan a folder and
 * render the files and folders contained into it.
 */
abstract class ListObject extends DataContainer {
  const LIST_ROOT = 'root';

  /** @var Node $node */
  protected $node;

  /** @var string $tpl */
  protected $tpl;

  /** @var int $limit */
  protected $limit;

  /** @var string $path */
  protected $path;

  /** @var array $protected_items */
  protected $items = array();

  /** @var array $rendered_items */
  public $rendered_items = array();

  /** @var array $attributes */
  private $attributes = array();

  /** @var array $replacements */
  protected $replacements = array();

  /** @var string $scantype */
  protected $scantype = \Quanta\Common\Environment::DIR_ALL;

  /** @var string $module */
  protected $module = 'list';

  /** @var bool $generated */
  protected $generated = FALSE;

  /** @var bool $loaded */
  public $loaded = FALSE;

  // Filter by status. TODO: discontinue.
  /** @var array $status */
  protected $status = array();

  /** @var string $sort */
  protected $sort;

  /**
   * ListObject constructor.
   * @param Environment $env
   *   The environment.
   * @param string $path
   *   The path (node) to use as a base for listing.
   * @param string $tpl
   *   The template file to use. I.e. dir for using dir.tpl.php.
   * @param array $attr_arr
   *   The attributes.
   * @param string $module
   *   The module in where to look for the template file (defaults to list).
   */
  public function __construct(&$env, $path, $tpl, $attr_arr = array(), $module = NULL) {
    $this->env = $env;
    $this->tpl = strtolower($tpl);

    // Check if the template is in a different module than the default.
    if (!empty($module)) {
      $this->module = $module;
    }

    // Support for "root" list.
    if ($path == self::LIST_ROOT) {
      $this->path = $env->dir['docroot'];
      $this->setNode(NULL);
      }
    elseif ($path == \Quanta\Common\Node::NODE_NEW) {
      $this->path = NULL;
      $this->setNode(NULL);
    }
    elseif ($path == NULL) {
      $this->setNode(NodeFactory::current($this->env));
      $this->path = $this->getNode()->path;
    }
    else {
      $this->setNode(NodeFactory::load($this->env, $path));
      $this->path = $this->getNode()->path;
    }

    foreach ($attr_arr as $attr_key => $attr_val) {
      $this->setAttribute($attr_key, $attr_val);
    }

    $this->setData('list_html_tag', !empty($this->getAttribute('list_html_tag')) ? $this->getAttribute('list_html_tag') : 'ul');
    $this->setData('list_item_html_tag', !empty($this->getAttribute('list_item_html_tag')) ? $this->getAttribute('list_item_html_tag') : 'li');

    $this->load();
  }

  /**
   * @return mixed
   */
  public function getModulePath() {
    $module = $this->env->getModule($this->module);
    return $module['path'];
  }

  /**
   * Set attributes, typically passed in the wiki tag.
   * @param $attr_name
   * @param $attr_value
   */

  public function setAttribute($attr_name, $attr_value) {
    $this->attributes[$attr_name] = $attr_value;
  }

  /**
   * Render the list and make it print-ready.
   * Must be extended.
   *
   * @param $attributes
   *   Attributes of the list.
   *
   * @return string
   *   The rendered HTML list.
   */
  public function render($attributes = array()) {

    // Check if the list was already generated. If not, generate it.
    if (!($this->generated)) {
      $this->generate();
      $this->generated = TRUE;
    }

    $separator = empty($this->getAttribute('separator')) ? '' : $this->getAttribute('separator');
    $output = implode($separator, $this->rendered_items);
    $classes = array();

    if (!empty($this->getAttribute('grid'))) {
      $classes[] = 'grid';
      if (!empty($this->getAttribute('grid_list'))) {
        $classes[] = $this->getAttribute('grid_list');
      }
    }

    $ajax = (!empty($this->getAttribute('ajax'))) ? ' rel="' . $this->getAttribute('ajax') . '"'  : '';
    $tpl = (!empty($this->getAttribute('tpl'))) ? ' data-tpl="' . $this->getAttribute('tpl') . '"' : '';

    if (!empty($this->getAttribute('empty_message')) && (count($this->rendered_items) == 0)) {
      $output = $this->getAttribute('empty_message');
    }

    // If the "clean" attribute is not present, add some wrapping html.
    if (empty($this->getAttribute('clean')))  {
      $output = '<' . $this->getData('list_html_tag') . ' '  . $ajax . $tpl . ' class="list ' . $this->getTpl() . ' list-' . $this->getTpl() . ' list-' . $this->node->getName() . ' ' . implode(' ', $classes) . '" data-node="' . $this->node->getName() . '">' . $output . '</' . $this->getData('list_html_tag') . '>';
    }

    // If the "nolinks" attribute is present, remove links from output.
    if ($this->getAttribute('nolinks')) {
      $output = preg_replace('/<a[^>]+\>/i', "", $output);
    }

    return $output;
  }

  /**
   * Load the list and all the files and directories.
   * @return bool
   */
  public function load() {
    // Empty path. Usually happens with a new node, that has no path yet.
    if (empty($this->path)) {
      $this->loaded = TRUE;
      return FALSE;
    }
    if (!is_dir($this->path)) {
      // Attempt to run a list on an invalid node.
      $this->loaded = TRUE;
      new Message($this->env, t('%node is not a valid page. Full path: %fullpath',
        array('%node' => $this->node->getName(), '%fullpath' =>  $this->path)
      ), MESSAGE_ERROR);
      return FALSE;
    }

    $this->start();

    $symlinks = $this->getAttribute('symlinks');

    // TODO: discontinue.
    if (!empty($this->getAttribute('status'))) {
      $this->status = array_flip(explode(',', $this->getAttribute('status')));
    }

    // Attribute used for applying filtering to a list's results.
    if (!empty($this->getAttribute('list_filter'))) {
      $this->setData('list_filter', $this->getAttribute('list_filter'));
    }

    if ($this->getAttribute('level') == 'leaf' || $this->getAttribute('level') == 'tree') {
      $list_pages = $this->env->scanDirectoryDeep($this->path, '', array(), array(
        'exclude_dirs' => \Quanta\Common\Environment::DIR_INACTIVE,
        'symlinks' => $symlinks,
        $this->scantype,
        'level' => $this->getAttribute('level')
      ));
    }
    else {
      $list_pages = $this->env->scanDirectory($this->path, array(
        'exclude_dirs' => \Quanta\Common\Environment::DIR_INACTIVE,
        'type' => $this->scantype,
        'symlinks' => $symlinks
      ));
    }

    foreach ($list_pages as $item) {

      $item_name = is_array($item) ? $item['name'] : $item;

      if ($this->scantype == \Quanta\Common\Environment::DIR_DIRS) {
        $node = NodeFactory::load($this->env, $item_name);

        if ($node->exists && $this->validateStatus($node)) {
          $this->addItem($node);
        }
      }
      elseif (($this->scantype == \Quanta\Common\Environment::DIR_FILES) && $this->node != NULL) {
				$file = new FileObject($this->env, $item_name, $this->node);
        if ($file->isPublic()) {
          $this->addItem($file);
        }
      }
    }

    $this->loadAttributes();

		return TRUE;

  }

  /**
   * Start the list generation.
   */
  public abstract function start();

  /**
   * Add an item to the list.
   *
   * @param $item
   *   The item to be added to the list.
   */
  public abstract function addItem($item);

  /**
   * Check if a node has a status that can be displayed in the list.
   *
   * @param Node $node
   *   The node for which to validate the status.
   *
   * @return bool
   *   Returns true if the node status is the one for which the list is filtered.
   *
   * TODO: deprecate and move as status filter.
   */
  public function validateStatus($node) {
     
		if (!empty($this->status)) {
      return (isset($this->status['all'])) || (isset ($this->status[$node->getStatus()]));
    }
    else {
      return TRUE;
    }
  }

  /**
   * Load all attributes invoked on the list.
   */
  private function loadAttributes() {
		foreach ($this->getAttributes() as $attr_name => $attr) {
      switch ($attr_name) {
        case 'reverse': {
          $this->items = array_reverse($this->items);
          break;
        }
        // TODO: Add default sort by weight.
        // Check list sorting.
        case 'sort':
          $this->sort = $attr;
          uasort($this->items, array($this, 'sortBy'));
          if (!empty($this->getAttribute('asc'))) {
            $this->items = array_reverse($this->items);
          }
          break;
        // Set a limit for items to display.
        case 'limit':
          $this->limit = $attr;
          break;

        default:
          // Unkown attribute for generic list - do nothing.
          // TODO: maybe add an error message?
          break;
      }
    }
	}

  /**
   * Replace function.
   * Adds automatic replacements in the list.
   *
   * @param $string
   *   The string to replace.
   *
   * @param $replacement
   *   The replacement for that string.
   */
	public function replace($string, $replacement) {
	  $this->replacements[$string] = $replacement;
  }

  /**
   * Gets the attributes of the list.
   *
   * @return array
   *   The attributes of the list.
   */
  public function getAttributes() {
    return $this->attributes;
  }

  /**
   * Gets a specific attribute of the list.
   *
   * @param $attr_name
   *   The attribute to get.
   *
   * @return mixed
   *   The attribute.
   */
  public function getAttribute($attr_name) {

    if (isset($this->attributes[$attr_name])) {
      return $this->attributes[$attr_name];
    }
    else {
      return FALSE;
    }
  }

  /**
   * Generate the list items.
   */
  public function generate() {
    if ($this->generated) {
      return;
    }
    $this->generateList();
    $this->generated = 1;
  }

  /**
   * Generate the list items (extended by modules implementing List).
   */
  abstract function generateList();

  /**
   * Return the template of this list.
   *
   * @return string
   *   The template of the list.
   */
  public function getTpl() {
    return $this->tpl;
  }

  /**
   * Check sorting in the list: compare two nodes during the cycle.
   *
   * @param Node $x
   *   The first node.
   *
   * @param Node $y
   *   The second node.
   *
   * @return int
   */
  abstract function sortBy($x, $y);

  /**
   * Unset the list elements.
   */
  public function clear() {
    $this->generated = TRUE;
    $this->rendered_items = array();
  }

  /**
   * Return items' count.
   *
   * @return integer
   */
  public function countItems() {
    //TODO: generate() should not be invoked here but rendered_items is not valorized otherwise
    if (!($this->generated)) {
      $this->generate();
      $this->generated = TRUE;
    }
    return count($this->rendered_items);
  }

  /**
   * Gets a loaded list item at a specific index.
   *
   * @param $index
   *   The specific index.
   *
   * @return mixed
   *   The list item.
   */
  public function getItem($index) {
    $items = array_values($this->items);
    if (isset($items[$index])) {
      $item = $items[$index];
    }
    else {
      new Message($this->env, 'Undefined list index: ' . $index . ' while listing ' . $this->getNode()->getName(), MESSAGE_ERROR);
      $item = '';
    }
    return $item;
  }

  /**
   * Gets the laoded list items (files or directories).
   *
   * @return array
   *   The loaded list items.
   */
  public function getItems() {
    return $this->items;
  }

  /**
   * Gets the rendered list items.
   *
   * @return array
   *   The rendered list items.
   */
  public function getRenderedItems() {
    return $this->rendered_items;
  }

  /**
   * Gets the lists' node.
   *
   * @return Node
   *   The lists' node.
   */
  public function getNode() {
    return $this->node;
  }

  /**
   * Sets the lists' node.
   *
   * @param Node
   *   The lists' node.
   */
  public function setNode($node) {
    $this->node = $node;
  }

}
