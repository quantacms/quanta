<?php
/**
 * Class Page
 * This class represents a Page (corrisponding to a rendered html page).
 */
class Page extends DataContainer {
  public $html;
  public $includes;
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
  }

  /**
   * Load all the includes (CSS JS etc.) of the page.
   */
  public function loadIncludes() {
    if (substr($_SERVER['REQUEST_URI'], 0, 7) == '/engine') die();
    // TODO: better way to skip load includes.
    if (isset($_REQUEST['ajax'])) { return; }

    // TODO: head variable not very nice. Discontinue in favor of js and css.
    $this->addData('head', array('<link rel="stylesheet" type="text/css" href="/tmp/css.min.css" />'));
    $this->addData('head', array('<script type="text/javascript" src="/tmp/js.min.js"></script>'));
    $this->addData('css', array('<link rel="stylesheet" type="text/css" href="/tmp/css.min.css" />'));
    $this->addData('js', array('<script type="text/javascript" src="/tmp/js.min.js"></script>'));

  }

  /**
   * Build the HTML of the page.
   * TODO: needs serious refactoring.
   */
  public function buildHTML() {

    // This is an AJAX request. Skip loading index.html and just provide requested content.
    if (isset($_REQUEST['ajax'])) {
      $this->html = $this->getData('content');
      return;
    }

    // This is an actual HTML page request. Commonly index.html.
    elseif ($this->file != NULL) {
      $this->html = file_get_contents($this->env->dir['docroot'] . '/' . $this->file);
    }

    // This is a special request, i.e. Shadow node edit.
    else {
      $this->html = $this->getData('content');
    }

    $this->env->hook('page_init', array("page" => &$this));
    $this->env->hook('metadata', array("page" => &$this));
    $this->env->hook('body_classes', array("page" => &$this));
    $this->env->hook('page_after_build', array("page" => &$this));
    $this->env->hook('page_complete', array("page" => &$this));

  }

  /**
   * Returns the rendered HTML page.
   * @return $string.
   */
  public function render() {
    return $this->html;
  }

}
