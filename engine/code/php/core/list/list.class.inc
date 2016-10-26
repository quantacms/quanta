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
  protected $tpl_items = array();
  private $attributes = array();
  protected $scantype = DIR_ALL;

  // Construct the List.

  public function __construct(&$env, $path, $type, $attr_arr = array()) {
    if ($path == NULL) {
      $path = $env->getRequestedPath();
    }

    $this->env = $env;
    // TODO: no need to rebuild node when it's current page.
    $this->dir = $path;
    $this->type = strtolower($type);

    if ($path == 'root') {
      $this->path = $env->dir['docroot'];
      $this->realpath = $env->dir['docroot'];
      $this->node = NULL;
    } else {
      $this->path = ($this->env->dir('wiki') . '/' . $path);
      $this->node = new Node($this->env, $path);
      $this->realpath = $this->node->realpath;
    }

    $this->tag = 'li';

    foreach ($attr_arr as $attr_key => $attr_val) {
        $this->setAttribute($attr_key, $attr_val);
    }
    $this->load();
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

    $output = implode('', $this->tpl_items);
    $ajax = (!empty($this->getAttribute('ajax'))) ? ' rel= ' .$this->getAttribute('ajax') : '';
    $output = '<ul ' . $ajax . ' class="list ' . $this->getTheme() . ' list-' . $this->getTheme() . ' list-' . $this->node->getName() . '">' . $output . '</ul>';
    return $output;
  }

  public function load() {
    if (!is_dir($this->realpath)) {
      $this->loaded = TRUE;
      new Message($this->env, $this->node->name . ' is not a valid page. Path: ' . $this->path . ' Full path: ' . $this->realpath, MESSAGE_ERROR);
      return FALSE;
    }

    $symlinks = $this->getAttribute('symlinks');

    if ($this->getAttribute('level') == 'leaf' || $this->getAttribute('level') == 'tree') {
			$list_pages = $this->env->scanDirectoryDeep($this->realpath, '', array(), array('exclude_dirs' => DIR_INACTIVE, 'symlinks' => $symlinks, $this->scantype, 'level' => $this->getAttribute('level')));
		} else {
      $list_pages = $this->env->scanDirectory($this->realpath, array('exclude_dirs' => DIR_INACTIVE, 'type' => $this->scantype, 'symlinks' => $symlinks));
    }

    foreach ($list_pages as $data_url) {

      if (($this->scantype == DIR_DIRS) && is_link($this->env->dir['wiki'] . '/' . $data_url)) {
        $node = new Node($this->env, $data_url);
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
          break;
        }
        case 'sortbytime':
          uasort($this->dirs, 'ListObject::sortByTime');
        break;

        case 'sortby':
          if ($attr == 'name') {
            ksort($this->dirs);
          }
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

  static function sortByTime($x, $y) {
		return ($x->getTimestamp() < $y->getTimestamp()) ? 1 : -1;
  }
}
