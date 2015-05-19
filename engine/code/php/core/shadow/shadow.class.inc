<?php
class Shadow {
  private $tabs = array();
  private $context;
  private $id;
  private $env;
  /**
   * @var Node $node.
   */
  private $node;

  /**
   * @param $context String
   * @param null $id
   */
  public function __construct($env, $context, $id = NULL) {
    $this->env = $env;
    $this->context = $context;
    $this->id = $id;
    $this->node = new Node($env, $env->getRequestedPath());
  }

  /**
   * Renders a Shadow using the Page class.
   */
  public function render() {
    $tabs = $this->getTabs();
    $tab_titles = '';
    $tab_contents = '';
    // TODO: refactor this crap.
    $shadow_page = new Page($this->env, NULL, 'Editing node:',
      str_replace('[NODE:body]', $this->node->getBody(),
      str_replace('[NODE:title]', $this->node->getTitle(),
        str_replace('[NODE:path]', $this->node->name,
        file_get_contents('core/shadow/tpl/' . $this->getContext() . '.inc')))));
    $i = 0;
    ksort($tabs);
    foreach ($tabs as $weight => $wtabs) {
      foreach ($wtabs as $tab) {
        $i++;
        // A tab can be null in case of single-page Shadows.
        if ($tab['title'] != NULL) {
          $tab_titles .= '<li class="shadow-title ' . (($i == 1) ? 'enabled' : '') . '" ><a href="#tab' . $i . '" rel="' . $i . '" id="shadow-title-' . $i . '">' . $tab['title'] . '</a></li>';
        }
        $tab_contents .= '<div class="shadow-content ' . (($i == 1) ? 'enabled' : '') . '" id="shadow-content-' . $i . '">' . $tab['content'] . '</div>';
      }
    }

    $shadow_page->setVariable('tab_titles', $tab_titles);
    $shadow_page->setVariable('tab_contents', $tab_contents);
    $shadow_page->setVariable('no_wiki', TRUE);
    $shadow_page->buildHTML();
    return $shadow_page->render();

  }

  /**
   * @param string $title
   * @param string $content
   * @param int $weight
   */
  public function addTab($title, $content, $weight = 1) {
    $this->tabs[$weight][] = array('title' => $title, 'content' => $content);
  }

  public function getTabs() {
    return $this->tabs;
  }

  public function getContext() {
    return $this->context;
  }
  public function setContext($context) {
    $this->context = $context;
  }

}