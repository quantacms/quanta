<?php
/**
 * Class DirList
 * This class is providing "listing" features useful to scan a folder and
 * render the files and folders contained into it.
 */
abstract class ListObject {
  public $loaded = FALSE;
  protected $path;
  protected $realpath;
  /**
   * @var Environment $env
   */
  protected $env;
  protected $node;
  protected $dir;
  protected $tpl;
  protected $contents;
  protected $limit;
  protected $dirs = array();
  protected $files = array();
  protected $list_items = array();
  private $attributes = array();
  protected $scantype = DIR_ALL;
  protected $module = 'list';
  // Exclude some nodes from listing. TODO: discontinue.
  protected $exclude = array();
  // Include only some nodes in listing. TODO: discontinue.
  protected $include = array();
  // Filter by status. TODO: discontinue.
  protected $status = array();
  // Filter
  protected $list_filter;

  /**
   * ListObject constructor.
   * @param $env
   *   The environment.
   * @param $path
   *   The path (node) to use as a base for listing.
   * @param $tpl
   *   The template file to use. I.e. dir for using dir.tpl.php.
   * @param array $attr_arr
   *   The attributes.
   * @param null $module
   *   The module in where to look for the template file (defaults to list).
   */
  public function __construct(&$env, $path, $tpl, $attr_arr = array(), $module = NULL) {
    if ($path == NULL) {
      $path = $env->getRequestedPath();
    }

    // Check if the template is in a different module than the default.
    if (!empty($module)) {
      $this->module = $module;
    }
    
		$this->env = $env;
    // TODO: no need to rebuild node when it's current page.
    $this->dir = $path;
    $this->tpl = strtolower($tpl);

    if ($path == 'root') {
      $this->path = $env->dir['docroot'];
      $this->realpath = $env->dir['docroot'];
      $this->node = NULL;
    }
    else {
      $this->path = $this->env->nodePath($path);
      $this->node = NodeFactory::load($this->env, $path);
      $this->realpath = $this->node->realpath;
    }

    $this->tag = 'li';
    foreach ($attr_arr as $attr_key => $attr_val) {
      $this->setAttribute($attr_key, $attr_val);
    }

    $this->load();
  }

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
    $this->theme();

    $separator = empty($this->getAttribute('separator')) ? '' : $this->getAttribute('separator');
    $output = implode($separator, $this->list_items);

    $ajax = (!empty($this->getAttribute('ajax'))) ? ' rel= ' . $this->getAttribute('ajax') : '';
    $tpl = (!empty($this->getAttribute('tpl'))) ? ' tpl= ' . $this->getAttribute('tpl') : '';

    if (!empty($this->getAttribute('empty_message')) && (count($this->list_items) == 0)) {
      $output = $this->getAttribute('empty_message');
    }

    // If the "clean" attribute is not present, add some wrapping html.
    if (empty($this->getAttribute('clean')))  {
      $output = '<ul ' . $ajax . $tpl . ' class="list ' . $this->getTpl() . ' list-' . $this->getTpl() . ' list-' . $this->node->getName() . '" data-node="' . $this->node->getName() . '">' . $output . '</ul>';
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
      new Message($this->env, $this->node->name . ' is not a valid page. Path: ' . $this->path . ' Full path: ' . $this->realpath, MESSAGE_ERROR);
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

          $this->dirs[$item_name] = $node;
        }
      }
      elseif (($this->scantype == DIR_FILES) && $this->node != NULL) {
				$file = new File($this->env, $item_name, $this->node);
        if ($file->isPublic()) {
          $this->files[] = $file;
        }
      }
    }

    $this->loadAttributes();

		return TRUE;

  }

  // Check if a node has a status that can be displayed in the list.
  public function validateStatus($node) {
    if (!empty($this->status)) {
      return (isset($this->status['all'])) || (isset ($this->status[$node->getStatus()]));
    } else {
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
          $this->dirs = array_reverse($this->dirs);
          $this->files = array_reverse($this->files);
          break;
        }
        // TODO: deprecate.
        case 'sortbytime':
          $this->sort = 'time';
          uasort($this->dirs, array($this, 'sortBy'));
          break;

        case 'sort':
          $this->sort = $attr;
          uasort($this->dirs, array($this, 'sortBy'));
          break;

        case 'limit':
          $this->limit = $attr;
          break;

        case 'exclude':
          $this->exclude = array_flip(explode(',', $attr));
          break;

        case 'include':
          $this->include = array_flip(explode(',', $attr));
          break;

        default:
          // Unkown attribute for generic list - do nothing.
          // @TODO: maybe add a message?
          break;
      }
    }
	}

  public function getAttributes() {
    return $this->attributes;
  }

  public function getAttribute($attr_name) {

    if (isset($this->attributes[$attr_name])) {
      return $this->attributes[$attr_name];
    }
    else {
      return FALSE;
    }
  }

  /**
   * Generate the html of the list.
   * Must be extended.
   */
  abstract public function theme();

  /**
   * Return the theme of this list.
   * @return string
   */
  public function getTpl() {
    return $this->tpl;
  }

  private function sortBy($x, $y) {

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


  /**
   * Return items' count.
   * @return integer
   */
  public function countItems() {
    //TODO: theme() should not be invoked here but list_items is not valorized otherwise
    $this->theme();
    return count($this->list_items);
  }

}
