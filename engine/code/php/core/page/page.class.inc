<?php
/**
 * Class Page
 * This class represents a Page (corrisponding to a rendered html page).
 */
class Page {
  public $html;
  public $includes;
  /**
   * @var Environment
   */
  public $env;
  public $body_classes = array();
  public $file;
  public $vars = array('head' => '', 'content' => '');

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
    $this->vars['title'] = $title;
    $this->env->hook('body_classes', array("page" => &$this));
    $this->vars['body-classes'] = implode(' ', $this->body_classes);
    $this->loadIncludes();
    $this->buildContent();
  }

  private function loadIncludes() {
    // Include JS / CSS files.
    foreach ($this->env->getIncludes() as $file) {
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

  private function buildContent() {
    // TODO: check access.
    $html = new DOMDocument();
    $this->vars['content'] = '<div id="data" class="data-' . $this->env->getRequestedPath() . '">' . $this->content . '</div>';
      @$html->loadHTML('<meta http-equiv="content-type" content="text/html; charset=utf-8">' . $this->vars['content']);
      $this->vars['content'] = $html->saveXML($html->getElementsByTagName('div')->item(0), LIBXML_NOEMPTYTAG);

      // TODO: better way to use tags.
      // this exmaple is for not applying nl2br on certain nodes.
      if (!(strpos($this->vars['content'], '@nobr') > 0)) {
        $this->vars['content'] = nl2br($this->vars['content']);
      }

      $this->setTitle(parsetag($this->vars['content'], 'h1'));

  }
  /**
   * Build the HTML of the page.
   * TODO: needs serious refactoring.
   */
  public function buildHTML() {
    // This is an actual HTML page request. Commonly index.html.
    if ($this->file != NULL) {
      $this->html = file_get_contents($this->env->dir['docroot'] . '/' . $this->file);
      $this->html = str_replace('[PAGE:content]', $this->vars['content'], $this->html);
    }
    // This is a special request, i.e. Shadow node edit.
    else {
      $this->html = $this->content;
    }
    $this->env->hook('page_init', array("page" => &$this));
    //TODO log_action($page, '!username is viewing this page: ' . $page['name'] . '.');
  }

  private function setTitle($title) {
    $this->vars['title'] = $title;
  }

  /**
   * Gets the title - h1 of the page.
   * @return $string
   */
  public function getTitle() {
    return $this->vars['title'];
  }

  public function setVariable($name, $content) {
    $this->vars[$name] = $content;
  }

  public function getVariable($name) {
    if (isset($this->vars[$name])) {
      return $this->vars[$name];
    } else {
      return FALSE;
    }
  }
  /**
   * Returns the rendered HTML page.
   * @return $string.
   */
  public function render() {
    return $this->html;
  }
}