<?php

/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 08/05/15
 * Time: 12:18
 */

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
  protected $type;
  protected $contents;
  protected $limit;
  protected $dirs = array();
  protected $files = array();
  protected $list_items = array();
  private $attributes = array();
  protected $scantype = DIR_ALL;
  protected $module;

  // Construct the List.

  public function __construct(&$env, $path, $type, $attr_arr = array(), $module = 'list') {
    if ($path == NULL) {
      $path = $env->getRequestedPath();
    }

    $this->module = $module;
    $this->env = $env;
    // TODO: no need to rebuild node when it's current page.
    $this->dir = $path;
    $this->type = strtolower($type);

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

    $output = implode('', $this->list_items);
    $ajax = (!empty($this->getAttribute('ajax'))) ? ' rel= ' . $this->getAttribute('ajax') : '';
    $tpl = (!empty($this->getAttribute('tpl'))) ? ' tpl= ' . $this->getAttribute('tpl') : '';

    // If the "clean" attribute is not present, add some wrapping html.
    if (empty($this->getAttribute('clean')))  {
      $output = '<ul ' . $ajax . $tpl . ' class="list ' . $this->getTheme() . ' list-' . $this->getTheme() . ' list-' . $this->node->getName() . '">' . $output . '</ul>';
    }

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

    foreach ($list_pages as $data_url) {

      if ($this->scantype == DIR_DIRS) {
        $node = NodeFactory::load($this->env, $data_url);
        if ($node->exists) {
          $this->dirs[$data_url] = $node;
        }
      }
      elseif (($this->scantype == DIR_FILES) && $this->node != NULL) {
				$file = new File($this->env, $data_url, $this->node);
        if ($file->isPublic()) {
          $this->files[] = $file;
        }
      }
    }

    $this->loadAttributes();
    
		return TRUE;

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
  public function getTheme() {
    return $this->getType();
  }

  /**
   * Return the type of this list.
   * @return string
   */
  public function getType() {
    return $this->type;
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
        $check = TRUE;
        break;

    }

    return ($check) ? 1 : -1;
  }
}
