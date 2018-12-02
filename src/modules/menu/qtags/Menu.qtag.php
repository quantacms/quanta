<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;
/**
 * Render a menu with links.
 */
class Menu extends Qtag {

  protected $menu_class = array('menu');
  protected $menu_list_class = array('menu-list');

  /**
   * Render the Qtag.
   *
   * TODO: refactor.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $links = array();
    $links_html = array();

    $menu_id = isset($this->attributes['menu_id']) ? $this->attributes['menu_id'] : '';

    if (isset($this->attributes['menu_class'])) {
      $this->menu_class += explode(' ', $this->attributes['menu_class']);
    }
    if (isset($this->attributes['menu_list_class'])) {
      $this->menu_list_class += explode(' ', $this->attributes['menu_list_class']);
    }

    if (isset($this->attributes['links'])) {
      // Get links directly.
      $links = explode('---', $this->attributes['links']);
    }
    elseif(!empty($this->getTarget())) {
      // Get links from target node.
      $rendered = NodeFactory::render($this->env, $this->getTarget());
      $links = explode("\n", trim($rendered));
    }

    foreach ($links as $link) {
      $links_html[] = '<li class="menu-link-item">' . $link . '</li>';
    }

    // Return the full nav.
    $html = '<nav' . (!empty($menu_id) ? ' id="' . $menu_id . '"' : '') . ' class="' . implode(' ', $this->menu_class) . '"><ul class="' . implode(' ', $this->menu_list_class) . '">' . implode($links_html) . '</ul></nav>';
    return $html;

  }
}
