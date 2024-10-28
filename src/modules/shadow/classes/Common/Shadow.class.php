<?php
namespace Quanta\Common;
use Quanta\Qtags\ShadowTab;

/**
 * Class Shadow
 */
class Shadow extends Page {
  private $tabs = array();
  private $widget;
  public $buttons = array();
  public $extra = array();
  public $components = array();
  public $single_title = '';
  public $priority = 0;
  /**
   * @var Node $node.
   */
  private $node;

  /**
   * Construct the Shadow.
   *
   * @param Environment $env
   *   The Environment.
   * @param object $data
   *   The shadow data.
   */
  public function __construct($env, $data = NULL) {
    $this->env = $env;
    $this->widget = $data['widget'];
    $this->setLanguage(isset($data['language']) ? $data['language'] : Localization::getLanguage($env));

    if (isset($data['components'])) {
      $this->components = $data['components'];
    }

    if (isset($data['redirect'])) {
      $this->setData('redirect', $data['redirect']);
    }

    // TODO: default to home is not making sense.
    $this->node = (isset($data['node'])) ? NodeFactory::load($env, $data['node'], $this->getLanguage()) : new Node($env, NULL);


      foreach ($data as $key => $value) {
        if (empty($this->getData($key))) {
          $this->setData($key, $value);
        }
      }


  }

  /**
   * Load all the components via hook.
   */
  public function loadComponents() {
    foreach ($this->components as $component) {
	    $vars = array('shadow' => &$this);
      $this->env->hook('shadow_' . $component, $vars);
    }
  }
  
  /**
   * Renders a Shadow using the Page class.
   */
  public function render() {
    $tabs = $this->getTabs();
    $tab_titles = '';
    $tab_contents = '';
    $enabled_tab = NULL;

    $i = 0;
    ksort($tabs);

    // Cycle through all the tabs of the overlay form (Shadow)
    // and render them.
    foreach ($tabs as $wtabs) {
      foreach ($wtabs as $tab) {
        $i++;
        $attr = array();

        $tab_title = new \Quanta\Qtags\ShadowTab($this->env, $attr, $i);
        $tab_content = new \Quanta\Qtags\ShadowContent($this->env, $attr, $i);
        $tab_title->setHtmlBody($tab['title']);

        // A tab can be null in case of single-page Shadows.
        if ($tab['title'] != NULL) {
          if (empty($enabled_tab)) {
            $enabled_tab = $i;
            $tab_title->addClass('enabled');
            $tab_content->addClass('enabled');
          }
          $tab_titles .= $tab_title->render();
        }
        else {
          $tab_content->addClass('hidden');
        }

        $tab_content->setHtmlBody($tab['content']);
        $tab_contents .= $tab_content->render();
      }
    }
    $this->setData('tab_titles', $tab_titles);
    $this->setData('single_title', $this->single_title);
    $this->setData('tab_contents', $tab_contents);
    $this->setData('buttons', $this->buttons);
    $this->setData('content', file_get_contents($this->env->getModulePath('shadow') . '/tpl/' . $this->getWidget() . '.inc'));
    $this->buildHTML();
    return '<div id="shadow-item" class="grid grid-gap-0">' . $this->html . '</div>';
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
   * Set Single Title to the shadow.
   * @param string $title
   * @param int $priority
   */
  public function setSingleTitle($title,$priority=0) {
    if ($priority >= $this->priority) {
      $this->priority = $priority;
      $this->single_title = '<h3 class="shadow-title">'.$title.'</h3>';
    }
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
   * @param double $weight
   *   The tab weight.
   * @param string $classes
   *   Classes of the tabbed item.
   */
  public function addTab($title, $content, $weight = 1, $classes = NULL) {
    while (isset($this->tabs[$weight])) {
      $weight += 1;
    }
    $this->tabs[$weight][$this->env->getContext()] = array('title' => $title, 'content' => $content, 'classes' => $classes);
  }

  /**
   * Add an extra HTML to the Shadow form.
   *
   * @param string $content
   *   The extra content.
   * @param int $weight
   *   The extra content weight.
   */
  public function addExtra($content, $weight = 1) {
    $this->addData('extra', array(array('content' => $content, 'weight' => $weight)));
  }

  /**
   * Get the Shadow's extra html.
   *
   * @return array
   *   The shadow's extra content.
   */
  public function getExtra() {
    return $this->getData('extra');
  }

  /**
   * Get the tabs of the shadow.
   *
   * @return array
   *   All the tabs of the shadow.
   */
  public function getTabs() {
    return $this->tabs;
  }

  /**
   * Get the widget used for the shadow.
   *
   * @return mixed
   *   The widget of the shadow.
   */
  public function getWidget() {
    return $this->widget;
  }

  /**
   * Get the node related to the shadow.
   *
   * @return Node
   *   The node for which the shadow has been opened.
   */
  public function getNode() {
    return $this->node;
  }

  /**
   * Check if there is an open shadow.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @return bool
   *   True if there is an open shadow request.
   */
  public static function isOpen($env) {
    return !empty($env->getData('shadow'));
  }
}
