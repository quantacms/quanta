<?php
class Shadow extends Page {
  private $tabs = array();
  private $widget;
  private $buttons = array();
  private $components = array();
  /**
   * @var Node $node.
   */
  private $node;

  /**
   * Construct the Shadow.
   *
   * @param Environment $env
   * @param object $data
   */
  public function __construct($env, $data = NULL) {
    $this->env = $env;
    $this->widget = $data->widget;
    $this->setLanguage(isset($data->language) ? $data->language : Locale::getLanguage($env));

    if (isset($data->components)) {
      $this->components = $data->components;
    }
    if (is_array($data)) {
      foreach ($data as $key => $value) {
        $this->setData($key, $value);
      }
    }
    if (isset($data->redirect)) {
      $this->setData('redirect', $data->redirect);
    }

    // TODO: default to home is not making sense.
    $this->node = (isset($data->node)) ? NodeFactory::load($env, $data->node, $this->getLanguage()) : new Node($env, NULL);

    foreach ($this->components as $component) {
      $this->env->hook('shadow_' . $component, array('shadow' => &$this));
    }
  }

  /**
   * Renders a Shadow using the Page class.
   */
  public function render() {
    $tabs = $this->getTabs();
    $tab_titles = '';
    $tab_contents = '';
    $i = 0;
    ksort($tabs);

    foreach ($tabs as $wtabs) {
      foreach ($wtabs as $tab) {
        $i++;
        // A tab can be null in case of single-page Shadows.
        if ($tab['title'] != NULL) {
          $tab_titles .= '<li class="shadow-title ' . (($i == 1) ? 'enabled' : '') . '" ><a href="#tab' . $i . '" data-rel="' . $i . '" id="shadow-title-' . $i . '">' . $tab['title'] . '</a></li>';
        }
        $tab_contents .= '<div class="shadow-content shadow-content-' . $this->env->getContext() . ' ' . $tab['classes'] . ' ' . (($i == 1) ? 'enabled' : '') . '" id="shadow-content-' . $i . '">' . $tab['content'] . '</div>';
      }
    }
    $this->setData('tab_titles', $tab_titles);
    $this->setData('tab_contents', $tab_contents);
    $this->setData('buttons', $this->buttons);
    $this->setData('content', file_get_contents('core/shadow/tpl/' . $this->getWidget() . '.inc'));
    $this->buildHTML();
    return $this->html;
  }

  /**
   * Add a submit button to the shadow.
   * @param $action
   * @param $button
   */
  public function addButton($action, $button) {
    $this->buttons[$action] = $button;
  }

  /**
   * Get the buttons.
   * @return array
   */
  public function getButtons() {
    return $this->buttons;
  }

  /**
   * @param string $title
   * @param string $content
   * @param int $weight
   * @param string $classes
   */
  public function addTab($title, $content, $weight = 1, $classes = NULL) {
    while (isset($this->tabs[$weight])) {
      $weight += 0.1;
    }
    $this->tabs[$weight][$this->env->getContext()] = array('title' => $title, 'content' => $content, 'classes' => $classes);
  }

  /**
   * Get the tabs of the shadow.
   * @return array
   */
  public function getTabs() {
    return $this->tabs;
  }

  /**
   * Get a tab of the shadow.
   * @param $context
   * @return bool
   */
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

  /**
   * Get the widget used for the shadow.
   * @return mixed
   */
  public function getWidget() {
    return $this->widget;
  }

  /**
   * Get the node related to the shadow.
   * @return \Node
   */
  public function getNode() {
    return $this->node;
  }

}
