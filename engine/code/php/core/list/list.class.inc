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
  /**
   * @var Environment $env
   */
  protected $env;
  protected $node;
  protected $dir;
  protected $type;
  protected $contents;
  protected $dirs = array();
  protected $files = array();
  protected $tpl_items = array();
  private $attributes = array();

  // Construct the List.

  public function __construct(&$env, $path, $type, $attr_arr = array()) {
    $this->env = $env;
    // TODO: no need to rebuild node when it's current page.
    $this->node = new Node($this->env, $path);
    $this->dir = $path;
    $this->type = strtolower($type);
    $this->path = $this->env->dir('wiki') . '/' . $path;
    foreach ($attr_arr as $attr_item) {
      $attr_item_arr = explode('=', $attr_item);
      if (count($attr_item_arr) == 2) {
        $this->setAttribute($attr_item_arr[0], $attr_item_arr[1]);
      } elseif (count($attr_item_arr) == 1) {
        $this->setAttribute($attr_item_arr[0], TRUE);
      }
    }
    $this->load();
  }

  /**
   * Set attribtues, typically passed in the wiki tag.
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
    $output = '<ul class="list ' . $this->getTheme() . '">' . implode('', $this->tpl_items) . '</ul>';
    return $output;
  }

  public function load() {
    if (!is_dir($this->node->realpath)) {
      $this->loaded = TRUE;
      new Message($this->env, $this->node->name . ' is not a valid page. Full path: ' . $this->node->realpath, MESSAGE_ERROR);
      return FALSE;
    }

    $list_pages = $this->env->scanDirectory($this->node->realpath);

    foreach ($list_pages as $data_url) {
      if (is_dir($list_dir = $this->env->dir['wiki'] . '/' . $data_url)) {
        $this->dirs[$data_url]['wiki'] = $this->env->dir['wiki'] . '/' . $data_url . '/data.html';
        $this->dirs[$data_url]['content'] = file_get_contents($this->dirs[$data_url]['wiki']);
        $this->dirs[$data_url]['title'] = parsetag($this->dirs[$data_url]['content'], 'h1');
        $this->dirs[$data_url]['timestamp'] = filemtime($this->dirs[$data_url]['wiki']);
      } else {
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
          default:
            // Unkown attribute for generic list - do nothing.
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

    return ($x['timestamp'] < $y['timestamp']) ? 1 : -1;
  }
}