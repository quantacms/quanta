<?php
namespace Quanta\Common;

define("FEED_FORMAT_RSS", "rss");
/**
 * Class Feed
 * This class represents a Feed (i.e. RSS).
 */
class Feed extends DataContainer {
  // TODO: this class is very useful, but needs a complete checkup.

  private $name;
  public $title;
  public $format;
  public $items;
  public $language;
  public $path;

  /**
   * @param $env Environment
   * @internal param $filename
   * @internal param null $name
   * @internal param null $content
   */
  public function __construct(&$env, $feed_name, $format = FEED_FORMAT_RSS, $path = NULL, $title = '') {
    $this->env = $env;
    $vars = array('feed' => &$this);
    $this->name = $feed_name;
    $this->language = Localization::getLanguage($env);
    $this->title = $title;
    $this->path = $path;
    $this->format = $format;

    $env->hook('feed_' . $feed_name, $vars);
    }

  /**
   * @return string
   */
  public function render() {
    // TODO: temporarily only supporting RSS.

    header("Content-type: text/xml");
    $output = '';
    if (!empty($this->path)) {

      $output .= "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n";
      $output .= "<rss version='2.0' xmlns:media=\"http://search.yahoo.com/mrss/\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\">\n";
      $output .= "<link>" . $this->env->request_uri . "</link>\n";
      $output .= "<language>" . $this->env->getLanguage() . "</language>\n";
      $output .= "<title>" . $this->title . "</title>\n";
      $output .= "<channel>\n";
      $list = new DirList($this->env, $this->path, 'rss', array(), 'feeds');
      $list->generate();
      $feed_nodes = $list->getItems();
      foreach ($feed_nodes as $feed_node) {
        $output .= "<item>";
        $item = array();
        $vars = array('feed_item' => &$item, 'node' => &$feed_node, 'feed' => &$this);
        $this->env->hook('feed_' . $this->name . '_item', $vars);
        foreach ($item as $k => $v) {
          $output .= "<" . $k . ">" . htmlspecialchars($v, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</" . $k . ">\n";
        }
        $output .= "</item>";
      }
      $output .= "</channel>";
      $output .= "</rss>";
      }
    return $output;
  }
}
