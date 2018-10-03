<?php
/**
 * Class Page
 * This class represents a Page (corresponding to a rendered html page).
 */
class Page extends DataContainer {
  public $html;
  public $includes;
  public $index_file;

  /**
   * Constructs the page object.
   *
   * @param $env Environment
   *   The Environment.
   *
   * @param $file
   *   The page file.
   *
   */
  public function __construct(&$env, $index_file = 'index.html') {
    $this->env = $env;
    $this->index_file = $index_file;
  }

  /**
   * Load all the includes (CSS JS etc.) of the page.
   */
  public function loadIncludes() {
    if (substr($this->env->request_uri, 0, 7) == '/engine') die();
    // TODO: better way to skip load includes.
    // TODO: using last run of doctor as token. Better solutions?
    $this->addCSS($this->env->dir['tmp_files'] . '/css.min.css');
    $this->addJS('/tmp/js.min.js');
  }

  /**
   * Adds a CSS to the page.
   *
   * @param mixed $css
   *   Could be the css code itself (if inline), or a link to the file.
   *
   * @param string $mode
   *   Can be file or inline.
   */
  public function addCSS($css, $mode = 'file') {
    if ($mode == 'inline') {
      $this->addData('css_inline', array($css));
    }
    else {
      $this->addData('css', array($css));
    }
  }

  /**
   * Adds a JS to the page.
   *
   * @param mixed $js
   *   Could be the js code itself (if inline), or a link to the file.
   *
   * @param string $mode
   *   Can be file or inline.
   */
  public function addJS($js, $mode = 'file') {
    if ($mode == 'inline') {
      $this->addData('js_inline', array($js));
    }
    else {
      $this->addData('js', array($js));
    }
  }

  /**
   * Build the HTML of the page.
   * TODO: needs serious refactoring.
   */
  public function buildHTML() {

    // This is an AJAX request. Skip loading index.html and just provide requested content.
    if (isset($_REQUEST['ajax'])) {
      $node = NodeFactory::current($this->env);
      $node->buildTemplate();
      $this->html = $node->render();
    }
    // This is an actual HTML page request. Commonly index.html.
    elseif (!empty($this->getIndexFile())) {
      $this->html = file_get_contents($this->env->dir['docroot'] . '/' . $this->getIndexFile());
    }

    // This is a special request, i.e. Shadow node edit.
    elseif (!empty($this->getData('content'))) {
      $this->html = $this->getData('content');
    }
    elseif (!is_file($this->env->dir['docroot'] . '/' . $this->getIndexFile())) {
      $this->html = t('Hello! Quanta seems not installed (yet) in your system. Please follow the <a href="https://www.quantacms.com/installation-instructions">Installation Instructions</a><br /><br />Reason: file not found(' . $this->getIndexFile() . ')');
    }

    $vars = array('page' => &$this);

    // Trigger various page hooks.
    // Page init.
    $this->env->hook('page_init', $vars);

    // Page body classes (TODO: not that beautiful?).
    $this->env->hook('body_classes', $vars);
    // Page after build.
    $this->env->hook('page_after_build', $vars);
    // Page complete.
    $this->env->hook('page_complete', $vars);
  }

  /**
   * Returns the rendered HTML page.
   * @return $string.
   */
  public function render() {
    return $this->html;
  }

  /**
   * Sets the path of the html file to be used as a page template.
   *
   * @param string
   *   The html file to use for the page rendering.
   */
  public function setIndexFile($index_file) {
    $this->index_file = $index_file;
  }

  /**
   * Gets the path of the html file to be used as a page template.
   *
   * @return string
   *   The html file to use for the page rendering.
   */
  public function getIndexFile() {
    return $this->index_file;
  }
}
