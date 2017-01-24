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
    $this->title = $title;
    $this->setData('title', $title);
  }

  /**
   * Load all the includes (CSS JS etc.) of the page.
   */
  public function loadIncludes() {
  // TODO: HYPER URGENT! Favicon request running the full stack!

    if ($_SERVER['REQUEST_URI'] == '/favicon.ico') die();

    if (isset($_REQUEST['ajax'])) { return; }
    //TODO: enforcing aggregation now.
    $css = array();
    $js = array();
    // Include JS / CSS files.
    foreach ($this->env->getIncludes() as $file) {

      if (substr($file['path'], 0, 6) == 'engine') {
        $file['path'] = $_SERVER['DOCUMENT_ROOT'] .'/' . $file['path'];
      }
      elseif (substr($file['path'], 0, 4) != 'http' && substr($file['path'], 0, 1) != '.') {
        $file['path'] = '/' . $file['path'];
        if ($file['type'] == 'css') {
          $this->vars['head'] .= '<link rel="stylesheet" type="text/css" href="' . $file['path'] . '" />';
        } elseif ($file['type'] == 'js') {
          $this->vars['head'] .= '<script type="text/javascript" src="' . $file['path'] . '"></script>';
        }
        continue;
      }

      if ($file['type'] == 'css') {
        $css[] = file_get_contents($file['path']);

      } elseif ($file['type'] == 'js') {
        $js[] = file_get_contents($file['path']);
      }
    }

    $temp_aggregate_path = md5(time() + rand(1,10000000));

    $temp_css_path = $this->env->dir['tmp_files'] . '/' . $temp_aggregate_path . '.css';
    $temp_js_path = $this->env->dir['tmp_files'] . '/' . $temp_aggregate_path . '.js';

    $fop = fopen($temp_css_path, 'w+');
    fwrite($fop, implode('
    ', $css));
    fclose($fop);

    $fop = fopen($temp_js_path, 'w+');
    fwrite($fop, implode('
    ', $js));
    fclose($fop);

    $this->addData('head', array('<link rel="stylesheet" type="text/css" href="/css/?D=' . $temp_aggregate_path . '" />'));
    $this->addData('head', array('<script type="text/javascript" src="/js/?D=' . $temp_aggregate_path . '"></script>'));

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
   * Sets the page title.
   * @param $title
   */
  public function setTitle($title) {
    $this->setData('title', $title);
  }

  /**
   * Sets the page content
   * TODO: deprecate?
   * @param $content
   */
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

}
