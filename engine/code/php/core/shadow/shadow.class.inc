<?php
class Shadow extends Page {
  private $tabs = array();
  private $context;
  private $type;
  private $buttons = array();
  /**
   * @var Node $node.
   */
  private $node;

  /**
   * @param $context String
   * @param null $id
   */
  public function __construct($env, $data) {
    $this->env = $env;
    $this->context = $data->context;
    $this->type = $data->type;
    if (isset($data->data)) {
      foreach ($data->data as $key => $value) {
        $this->setData($key, $value);
      }
    }
    $this->node = new Node($env, $env->getRequestedPath());

    $this->env->hook('shadow_' . $this->context, array('shadow' => &$this));
    if ($this->getTabs() == NULL) {
      new Message($this->env, 'There is no hook_shadow_' . $this->context . ' defined', MESSAGE_ERROR);
    }
  }

  /**
   * Renders a Shadow using the Page class.
   */
  public function render() {
    $tabs = $this->getTabs();
    $tab_titles = '';
    $tab_contents = '';
    // TODO: SUPER @URGENT URGENT refactor this crap.
    $shadow_page = new Page($this->env, NULL, 'Shadow', file_get_contents('core/shadow/tpl/' . $this->getType() . '.inc'));
    $shadow_page->setData('node', $this->node);
    $i = 0;
    ksort($tabs);

    foreach ($tabs as $wtabs) {
      foreach ($wtabs as $tab) {
        $i++;
        // A tab can be null in case of single-page Shadows.
        if ($tab['title'] != NULL) {
          $tab_titles .= '<li class="shadow-title ' . (($i == 1) ? 'enabled' : '') . '" ><a href="#tab' . $i . '" rel="' . $i . '" id="shadow-title-' . $i . '">' . $tab['title'] . '</a></li>';
        }
        $tab_contents .= '<div class="shadow-content shadow-content-' . $this->getContext() . ' ' . $tab['classes'] . ' ' . (($i == 1) ? 'enabled' : '') . '" id="shadow-content-' . $i . '">' . $tab['content'] . '</div>';
      }
    }
    $shadow_page->setData('tab_titles', $tab_titles);
    $shadow_page->setData('tab_contents', $tab_contents);
    $shadow_page->setData('buttons', $this->buttons);
    $shadow_page->buildHTML();

    return $shadow_page->render();
  }

  /**
   * Add a submit button to the shadow
   */
  public function addButton($action, $button) {
    $this->buttons[$action] = $button;
  }

  public function getButtons() {
    return $this->buttons;
  }
  /**
   * @param string $title
   * @param string $content
   * @param int $weight
   */
  public function addTab($title, $content, $weight = 1, $classes = NULL) {
    $this->tabs[$weight][$this->getContext()] = array('title' => $title, 'content' => $content, 'classes' => $classes);
  }

  public function getTabs() {
    return $this->tabs;
  }

  public function prependToTab($context, $text) {
    $tab = $this->getTab($context);
    $this->tabs[1]['user_register']['content'] = $this->tabs[1]['user_register']['content'] . $text;
  }

  public function getTab($context) {
    foreach ($this->tabs as $weight => $tab) {
      foreach ($tab as $tab_context => $content) {
        if ($context == $tab_context) {
          return $this->tabs[$weight][$tab_context];
        }
      }
    }
    return FALSE;
  }
  public function getContext() {
    return $this->context;
  }

  public function getType() {
    return $this->type;
  }

  public function setContext($context) {
    $this->context = $context;
  }

}