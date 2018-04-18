<?php

/**
 * Class Shadow
 */
class Shadow extends Page {
  private $tabs = array();
  private $widget;
  public $buttons = array();
  public $components = array();
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
    $this->setLanguage(isset($data->language) ? $data->language : Localization::getLanguage($env));

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

    // TODO: urgently refactor.

    if (isset($data->manager)) {
      $this->setData('manager', $data->manager);
    }

    // TODO: default to home is not making sense.
    $this->node = (isset($data->node)) ? NodeFactory::load($env, $data->node, $this->getLanguage()) : new Node($env, NULL);

  }

  /**
   * Load all the components via hook.
   */
  public function loadComponents() {
    foreach ($this->components as $component) {
      $vars = array('shadow' => &$this);
      $this->env->hook('shadow_' . $component, $vars);
    }

    // TODO: change this total shit and use another criteria for adding standard node data!
    // TODO: IMPORTANT!
    if ($this->env->getContext() != USER_ACTION_LOGIN) {
      $this->addTab('Temporary form', file_get_contents('core/node/tpl/node_data.inc'), 1);
      $this->addButton('edit-save', 'Save');
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

    // Cycle through all the tabs of the overlay form (Shadow)
    // and render them.
    foreach ($tabs as $wtabs) {
      foreach ($wtabs as $tab) {
        $i++;
        // A tab can be null in case of single-page Shadows.
        if ($tab['title'] != NULL) {
          $tab_titles .= '<li class="shadow-title ' . (($i == 1) ? 'enabled' : '') . '" ><a href="#" data-rel="' . $i . '" id="shadow-title-' . $i . '">' . $tab['title'] . '</a></li>';
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
   * Add a tab to the Shadow form.
   *
   * @param string $title
   *   The tab title.
   * @param string $content
   *   The tab content.
   * @param int $weight
   *   The tab weight.
   * @param string $classes
   *   Classes of the tabbed item.
   */
  public function addTab($title, $content, $weight = 1, $classes = NULL) {
    while (isset($this->tabs[$weight])) {
      $weight += 0.1;
    }
    $this->tabs[$weight][$this->env->getContext()] = array('title' => $title, 'content' => $content, 'classes' => $classes);
  }

  /**
   * Get the tabs of the shadow.
   *
   * @return array
   *   Al the tabs of the shadow.
   */
  public function getTabs() {
    return $this->tabs;
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
