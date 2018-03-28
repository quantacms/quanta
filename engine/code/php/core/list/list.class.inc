<?php
define('LIST_ROOT', 'root');
/**
 * Class DirList
 * This class is providing "listing" features useful to scan a folder and
 * render the files and folders contained into it.
 */
abstract class ListObject extends DataContainer {
  public $loaded = FALSE;
  protected $realpath;
  /**
   * @var Environment $env
   */
  protected $node;
  protected $tpl;
  protected $limit;
  /** @var array */
  protected $items = array();
  /** @var array */
  public $rendered_items = array();
  /** @var array */
  private $attributes = array();
  /** @var array */
  protected $replacements = array();
  protected $scantype = DIR_ALL;
  protected $module = 'list';
  protected $generated = FALSE;
  // Filter by status. TODO: discontinue.
  protected $status = array();
  // Filter
  protected $list_filter;
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
    if ($path == LIST_ROOT) {
      $this->realpath = $env->dir['docroot'];
      $this->setNode(NULL);
      }
    elseif ($path == NULL) {
      $this->setNode(NodeFactory::current($this->env));
      $this->realpath = $this->getNode()->realpath;
    }
    else {
      $this->setNode(NodeFactory::load($this->env, $path));
      $this->realpath = $this->getNode()->realpath;
    }

    $this->tag = 'li';

    foreach ($attr_arr as $attr_key => $attr_val) {
      $this->setAttribute($attr_key, $attr_val);
    }

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
   */
  public function render() {

    // Check if the list was already generated. If not, generate it.
    if (!($this->generated)) {
      $this->generate();
      $this->generated = TRUE;
    }

    $separator = empty($this->getAttribute('separator')) ? '' : $this->getAttribute('separator');
    $output = implode($separator, $this->rendered_items);

    $ajax = (!empty($this->getAttribute('ajax'))) ? ' rel= ' . $this->getAttribute('ajax') : '';
    $this->setData('list_html_tag', !empty($this->getAttribute('ordered')) ? 'ol' : 'ul');

    $tpl = (!empty($this->getAttribute('tpl'))) ? ' tpl= ' . $this->getAttribute('tpl') : '';

    if (!empty($this->getAttribute('empty_message')) && (count($this->rendered_items) == 0)) {
      $output = $this->getAttribute('empty_message');
    }

    // If the "clean" attribute is not present, add some wrapping html.
    if (empty($this->getAttribute('clean')))  {
      $output = '<' . $this->getData('list_html_tag') . ' '  . $ajax . $tpl . ' class="list ' . $this->getTpl() . ' list-' . $this->getTpl() . ' list-' . $this->node->getName() . '" data-node="' . $this->node->getName() . '">' . $output . '</' . $this->getData('list_html_tag') . '>';
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
    if (!is_dir($this->realpath)) {
      $this->loaded = TRUE;
      new Message($this->env, $this->node->name . ' is not a valid page. Full path: ' . $this->realpath, MESSAGE_ERROR);
      return FALSE;
    }

    $symlinks = $this->getAttribute('symlinks');

    // TODO: discontinue.
    if (!empty($this->getAttribute('status'))) {
      $this->status = array_flip(explode(',', $this->getAttribute('status')));
    }

    if (!empty($this->getAttribute('list_filter'))) {
      $this->list_filter = $this->getAttribute('list_filter');
    }

    if ($this->getAttribute('level') == 'leaf' || $this->getAttribute('level') == 'tree') {
      $list_pages = $this->env->scanDirectoryDeep($this->realpath, '', array(), array(
        'exclude_dirs' => DIR_INACTIVE,
        'symlinks' => $symlinks,
        $this->scantype,
        'level' => $this->getAttribute('level')
      ));
    }
    else {
      $list_pages = $this->env->scanDirectory($this->realpath, array(
        'exclude_dirs' => DIR_INACTIVE,
        'type' => $this->scantype,
        'symlinks' => $symlinks
      ));
    }

    foreach ($list_pages as $item) {

      $item_name = is_array($item) ? $item['name'] : $item;

      if ($this->scantype == DIR_DIRS) {
        $node = NodeFactory::load($this->env, $item_name);
        if ($node->exists && $this->validateStatus($node)) {

          $this->items[$item_name] = $node;
        }
      }
      elseif (($this->scantype == DIR_FILES) && $this->node != NULL) {
				$file = new File($this->env, $item_name, $this->node);
        if ($file->isPublic()) {
          $this->items[] = $file;
        }
      }
    }

    $this->loadAttributes();

		return TRUE;

  }


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
      return $node->isPublished();
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
   * Replace a function.
   */
	public function replace($string, $replacement) {
	  $this->replacements[$string] = $replacement;
  }

  /**
   * @return array
   */
  public function getAttributes() {
    return $this->attributes;
  }

  /**
   * @param $attr_name
   * @return bool|mixed
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
   * Gets a loaded list item.
   *
   * @return mixed
   *   The list item.
   */
  public function getItem($index) {
    if (isset($this->items[$index])) {
      $item = $this->items[$index];
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
