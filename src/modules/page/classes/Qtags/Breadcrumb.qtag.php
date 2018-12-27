<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;

/**
 * Renders the full breadcrumb / lineage of the current node.
 */
class Breadcrumb extends HtmlTag {
  protected $html_tag = 'ul';
  protected $html_params = array('class' => 'breadcrumb');
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = NodeFactory::current($this->env);
    $node_home = NodeFactory::load($this->env, 'home');
    // Build the lineage of the node.
    $node->buildLineage();
    $breadcrumb = $node->getLineage();
    // First item in breadcrumb is the home node, unless excluded by an attribute.
    if (empty($this->attributes['exclude_home'])) {
      $breadcrumb = array('home' => $node_home) + $breadcrumb;
    }
    // Attribute to include current node in the breadcrumb.
    if (!empty($this->attributes['include_current'])) {
      $breadcrumb += array($node->name => $node);
    }
    // Generate the Breadcrumb items.
    if (!empty($breadcrumb)) {
      foreach ($breadcrumb as $i => $node) {
        // Add only published nodes without "breadcrumb_exclude" param in json.
        if ($node->isPublished() && !$node->getAttributeJSON('breadcrumb_exclude')) {
          $link_attr = array('link_class' => 'breadcrumb-link');
          $link = new Link($this->env, $link_attr, $node->getName());
          $this->html_body .= '<li>' . $link->render() . '</li>';
        }
      }
    }
    return parent::render();
  }
}
