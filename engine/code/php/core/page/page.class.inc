<?php
/**
 * Class Page
 * This class represents a Page (corresponding to a rendered html page).
 */
class Page extends DataContainer {
  public $html;
  public $includes;
  public $file;

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
  public function __construct(&$env, $file = 'index.html') {
    $this->env = $env;
    $this->file = $file;
  }

  /**
   * Load all the includes (CSS JS etc.) of the page.
   */
  public function loadIncludes() {
    if (substr($this->env->request_uri, 0, 7) == '/engine') die();
    // TODO: better way to skip load includes.
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
      $node = NodeFactory::current($this->env);
      $node->buildTemplate();
      $this->html = $node->render();
    }

    // This is an actual HTML page request. Commonly index.html.
    elseif ($this->file != NULL) {
      $this->html = file_get_contents($this->env->dir['docroot'] . '/' . $this->file);
    }

    // This is a special request, i.e. Shadow node edit.
    else {
      $this->html = $this->getData('content');
    }

    $vars = array('page' => &$this);

    // Trigger various page hooks.
    // Page init.
    $this->env->hook('page_init', $vars);
    // Page metadata.
    $this->env->hook('metadata', $vars);
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

}
