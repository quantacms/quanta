<?php
/**
 * Class Page
 * This class represents a Page (corrisponding to a rendered html page).
 */
class Page extends DataContainer {
  public $html;
  public $includes;
  public $body_classes = array();
  public $file;

  /**
   * @param $env Environment
   * @param $filename
   * @param null $name
   * @param null $content
   */
  public function __construct(&$env, $file, $title = NULL, $content = NULL) {
    $this->env = $env;
    $this->file = $file;
    $this->title = $title;
    $this->content = $content;
    $this->setData('title', $title);
  }

  public function loadIncludes() {

    // Include JS / CSS files.
    foreach ($this->env->getIncludes() as $file) {
      if (substr($file['path'], 0, 4) != 'http' && substr($file['path'], 0, 1) != '.') {
        $file['path'] = '/' . $file['path'];
      }
      if ($file['type'] == 'css') {
        $this->vars['head'] .= '<link rel="stylesheet" type="text/css" href="' . $file['path'] . '" />';
      } elseif ($file['type'] == 'js') {
        $this->vars['head'] .= '<script type="text/javascript" src="' . $file['path']. '"></script>
      ';
      }
    }
  }

  public function addBodyClass($class) {
    $this->body_classes[] = strtolower(trim($class));
  }

  public function buildContent() {
    // TODO: check access.
    $html = new DOMDocument();
      @$html->loadHTML('<meta http-equiv="content-type" content="text/html; charset=utf-8">' . $this->getData('content'));
      $this->setData('content', $html->saveXML($html->getElementsByTagName('div')->item(0)));

      // TODO: better way to use tags.
      // this exmaple is for not applying nl2br on certain nodes.
      if (!(strpos($this->getData('content'), '@nobr') > 0)) {
        $this->setData('content', nl2br($this->getData('content')));
      }

      $this->setTitle(parsetag($this->getData('content'), 'h1'));
  }
  /**
   * Build the HTML of the page.
   * TODO: needs serious refactoring.
   */
  public function buildHTML() {

    if (isset($_REQUEST['ajax'])) {
      $this->html = $this->getData('content');
    }
    // This is an actual HTML page request. Commonly index.html.
    elseif ($this->file != NULL) {
      $this->html = file_get_contents($this->env->dir['docroot'] . '/' . $this->file);
      $this->html = str_replace('[PAGE:content]', $this->getData('content'), $this->html);
    }
    // This is a special request, i.e. Shadow node edit.
    else {
      $this->html = $this->getData('content');
    }

    $this->env->hook('page_init', array("page" => &$this));
    $this->env->hook('page_after_build', array("page" => &$this));
    $this->env->hook('page_complete', array("page" => &$this));

  }

  public function setTitle($title) {
    $this->setData('title', $title);
  }

  public function setContent($content) {
    $this->setData('content', $content);
  }

  /**
   * Gets the title - h1 of the page.
   * @return $string
   */
  public function getTitle() {
    return $this->getData('title');
  }

  /**
   * Returns the rendered HTML page.
   * @return $string.
   */
  public function render() {
    return $this->html;
  }

  public function loadJSON() {
    // TODO: Implement loadJSON() method.
  }

  public function updateJSON($ignore = array()) {
    // TODO: Implement updateJSON() method.
  }
}
