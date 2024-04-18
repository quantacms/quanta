<?php
namespace Quanta\Qtags;
/**
 * Render a menu with links.
 */
class Menu extends HtmlTag {

  protected $html_tag = 'nav';
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

    if (isset($this->attributes['menu_list_class'])) {
      $this->menu_list_class += explode(' ', $this->attributes['menu_list_class']);
    }

    if (isset($this->attributes['links'])) {
      // Get links directly.
      $links = explode('---', $this->attributes['links']);
    }
    elseif(!empty($this->getTarget())) {
      // Get links from target node.
      $rendered = \Quanta\Common\NodeFactory::render($this->env, $this->getTarget());
      $links = explode("\n", trim($rendered));
    }

    $i = 0;
    foreach ($links as $link) {
      $i++;
      $links_html[] = '<li class="menu-link-item menu-link-item-' . $i . '">' . $link . '</li>';
    }

    // Return the full nav.
    $this->html_body = '<ul class="' . implode(' ', $this->menu_list_class) . '">' . implode($links_html) . '</ul>';

    return parent::render();

  }
}
